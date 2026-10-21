<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\DependencyInjection;

use Nelmio\SecurityBundle\NelmioSecurityBundle;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Config\FileLocator;
use Tito10047\UX\Sdc\CompilerPass\AssetComponentCompilerPass;
use Tito10047\UX\Sdc\CompilerPass\ComponentRoutesCompilerPass;
use Tito10047\UX\Sdc\CompilerPass\StimulusControllerPathsCompilerPass;
use Tito10047\UX\Sdc\Csp\NelmioNonceProvider;
use Tito10047\UX\Sdc\Debug\ComponentInspector;
use Tito10047\UX\Sdc\EventListener\AssetResponseListener;
use Tito10047\UX\Sdc\EventListener\ComponentRenderListener;
use Tito10047\UX\Sdc\EventListener\DevComponentRenderListener;
use Tito10047\UX\Sdc\Response\PageAssetInjector;
use Tito10047\UX\Sdc\Routing\ComponentRouteLoader;
use Tito10047\UX\Sdc\Runtime\SdcMetadataRegistry;
use Tito10047\UX\Sdc\Service\ComponentMetadataResolver;
use Tito10047\UX\Sdc\Twig\AssetExtension;

class SdcExtension extends Extension implements PrependExtensionInterface
{
    /**
     * The Twig namespace `ux_components_dir` is registered under, besides the
     * main one: `@component/Alert/Alert.html.twig` names a file of that
     * directory and of no other.
     */
    public const TWIG_NAMESPACE = 'component';

    public function getAlias(): string
    {
        return 'ux_sdc';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.php');

        $container->setParameter('ux_sdc.auto_discovery', $config['auto_discovery']);
        $container->setParameter('ux_sdc.ux_components_dir', $config['ux_components_dir']);

        // Read by StimulusControllerPathsCompilerPass, which adds the component
        // directory to the controller paths StimulusBundle resolved.
        $container->setParameter(
            StimulusControllerPathsCompilerPass::ENABLED_PARAMETER,
            $this->isConfigEnabled($container, $config['stimulus']),
        );

        $this->registerMetadataResolver($container, $config);

        // One decision, made here and read by AssetComponentCompilerPass too: the
        // pass dumps the metadata cache exactly when the listener reading it is kept.
        $metadataCache = $this->isMetadataCacheEnabled($container, $config['metadata_cache']);
        $container->setParameter(AssetComponentCompilerPass::METADATA_CACHE_PARAMETER, $metadataCache);
        $container->removeDefinition($metadataCache ? DevComponentRenderListener::class : ComponentRenderListener::class);
        if ($container->hasDefinition(ComponentInspector::class)) {
            $container->getDefinition(ComponentInspector::class)->setArgument('$metadataCache', $metadataCache);
        }

        $this->registerNelmioNonceProvider($container);

        // Printed by render_component_assets(), looked for by the listener,
        // replaced by the injector.
        foreach ([AssetExtension::class, AssetResponseListener::class, PageAssetInjector::class] as $id) {
            $container->getDefinition($id)->setArgument('$placeholder', $config['placeholder']);
        }

        if (null !== $config['csp_nonce_provider']) {
            $container->getDefinition(PageAssetInjector::class)
                ->setArgument('$nonceProvider', new Reference($config['csp_nonce_provider']));
        }

        $namespace = isset($config['component_namespace']) ? self::normalizeNamespace((string) $config['component_namespace']) : null;
        $container->setParameter('ux_sdc.component_namespace', $namespace);

        $uxComponentsDir = $container->resolveEnvPlaceholders($config['ux_components_dir'], true);

        if (null !== $namespace) {
            // Not created yet: prepend() makes the container track the
            // directory, so that it is rebuilt once the directory exists.
            if (file_exists($uxComponentsDir)) {
                $this->registerClasses($container, $namespace, $uxComponentsDir);
            }
        }

        $this->configureRouting($container, $namespace, $this->isConfigEnabled($container, $config['routing']), $uxComponentsDir);

        // A convenience alias of ux_sdc.ux_components_dir, for applications that
        // want to point their own configuration at the same directory.
        //
        // A parameter, and only a parameter. There used to be a service of this
        // name here too, registered with "string" as its class: nothing ever
        // instantiated it, but `lint:container` walks every definition and died
        // on 'class "string" does not exist', which took away the one command
        // that tells an application whether its container is sound.
        $container->setParameter('app.ui_components.dir', $config['ux_components_dir']);

        $container->register(SdcMetadataRegistry::class)
            ->setArguments([
                '$cachePath' => '%kernel.cache_dir%/sdc_metadata.php',
                '$cacheEnabled' => $metadataCache,
                '$logger' => new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ])
            ->addTag('monolog.logger', ['channel' => 'ux_sdc'])
            ->setPublic(true); // Set to true for easier testing in Integration tests
    }

    public function prepend(ContainerBuilder $container): void
    {
        $configs = $container->getExtensionConfig('ux_sdc');

        $config = [];
        foreach ($configs as $c) {
            $config = array_merge($config, $c);
        }

        $uxComponentsDir = $config['ux_components_dir'] ?? '%kernel.project_dir%/src_component';
        $uxComponentsDir = $container->resolveEnvPlaceholders($uxComponentsDir, true);

        // Right after `composer require`, before the first component is made,
        // the directory does not exist yet, and both Twig's FilesystemLoader and
        // AssetMapper (in debug) throw on a path that does not exist: Twig as
        // soon as the "twig" service is created, which took down every page of
        // the application. The directory is therefore registered only once it
        // exists, the way TwigBundle treats its own default path, and the
        // container tracks its existence so that a debug container is rebuilt
        // when it appears. A container that is never rebuilt — production,
        // warmed up before the directory was created — keeps going without it.
        $uxComponentsDirPath = $this->filesystemPath($container, $uxComponentsDir);
        $container->addResource(new FileExistenceResource($uxComponentsDirPath));
        $uxComponentsDirExists = is_dir($uxComponentsDirPath);

        if ($uxComponentsDirExists) {
            $container->prependExtensionConfig('twig', [
                'paths' => [
                    rtrim($uxComponentsDir, DIRECTORY_SEPARATOR) => null,
                    $uxComponentsDir.DIRECTORY_SEPARATOR => self::TWIG_NAMESPACE,
                ],
            ]);
        }

        $paths = [];
        if ($uxComponentsDirExists) {
            $paths[] = $uxComponentsDir;
        }
        if ($this->isAssetMapperAvailable($container)) {
            $paths[__DIR__.'/../../assets/controllers'] = '@tito10047/ux-sdc';
        }
        $assetMapper = ['paths' => $paths];
        if ($uxComponentsDirExists && $this->excludesTemplatesFromAssetMap($container, $config)) {
            $assetMapper['excluded_patterns'] = [self::templateExclusionPattern($uxComponentsDir)];
        }
        $container->prependExtensionConfig('framework', [
            'asset_mapper' => $assetMapper,
        ]);

        if (isset($config['component_namespace'])) {
            $namespace = self::normalizeNamespace((string) $config['component_namespace']);
            $container->prependExtensionConfig('twig_component', [
                'defaults' => [
                    $namespace => [
                        'template_directory' => '',
                    ],
                ],
            ]);
        }
    }

    /**
     * The adapter for NelmioSecurityBundle, available to `csp_nonce_provider` when
     * the bundle is enabled. It refers to Nelmio's CSP listener, which exists only
     * with Nelmio's `csp` feature on; unless `csp_nonce_provider` selects it, the
     * adapter is private and unused, and the container removes it without
     * resolving that reference.
     */
    private function registerNelmioNonceProvider(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('kernel.bundles')) {
            return;
        }

        /** @var array<string, class-string> $bundles */
        $bundles = $container->getParameter('kernel.bundles');

        if (!\in_array(NelmioSecurityBundle::class, $bundles, true)) {
            return;
        }

        $container->register(NelmioNonceProvider::class, NelmioNonceProvider::class)
            ->setArguments([new Reference('nelmio_security.csp_listener')]);
    }

    /**
     * The "ux_sdc" route import loads the component directory when routing is
     * enabled and there is a component namespace — without one, the bundle
     * registers no component classes to route. Otherwise it loads nothing, and
     * with routing disabled ComponentRoutesCompilerPass also keeps the classes
     * of the namespace out of Symfony 7.4's `resource: routing.controllers`.
     */
    private function configureRouting(ContainerBuilder $container, ?string $namespace, bool $enabled, string $uxComponentsDir): void
    {
        $routed = $enabled && null !== $namespace;

        $container->getDefinition(ComponentRouteLoader::class)
            ->replaceArgument(0, $routed ? $this->filesystemPath($container, $uxComponentsDir) : null)
            ->replaceArgument(1, $routed ? $namespace : null);

        $container->setParameter('ux_sdc.routing.enabled', $enabled);
        $container->setParameter(ComponentRoutesCompilerPass::DISABLED_NAMESPACE_PARAMETER, $routed ? null : $namespace);
    }

    /**
     * The directory a configured path names on the filesystem: parameters and
     * environment variables resolved, unescaped, and relative to the project
     * directory unless absolute — as Twig and AssetMapper read it.
     */
    private function filesystemPath(ContainerBuilder $container, string $path): string
    {
        $unescaped = $container->getParameterBag()->unescapeValue($path);
        if (\is_string($unescaped)) {
            $path = $unescaped;
        }

        if ((new Filesystem())->isAbsolutePath($path) || !$container->hasParameter('kernel.project_dir')) {
            return $path;
        }

        $projectDir = $container->getParameter('kernel.project_dir');

        return \is_string($projectDir) ? $projectDir.'/'.$path : $path;
    }

    /**
     * Resolves `metadata_cache`, where "auto" follows kernel.debug: a debug
     * container resolves metadata on render, any other uses the build-time cache.
     * The environment name plays no part, so `staging` or `prod_debug` get the
     * pipeline their debug flag calls for.
     */
    private function isMetadataCacheEnabled(ContainerBuilder $container, bool|string $metadataCache): bool
    {
        if (\is_bool($metadataCache)) {
            return $metadataCache;
        }

        // Configuration lets through nothing else but environment variable
        // placeholders. The value picks services when the container is built,
        // so it could never follow a variable that changes afterwards.
        if ('auto' !== $metadataCache) {
            throw new InvalidConfigurationException('The "ux_sdc.metadata_cache" option cannot come from an environment variable: it selects services when the container is built. Set it to true, false or "auto".');
        }

        // Every kernel sets it. A container built without one is a production
        // build as far as anything else is concerned, so it gets the cache.
        if (!$container->hasParameter('kernel.debug')) {
            return true;
        }

        // kernel.debug may be bound to an environment variable. The listeners are
        // chosen now, once, so the variable is read now, at build time.
        $debug = $container->resolveEnvPlaceholders($container->getParameter('kernel.debug'), true);
        $isDebug = \is_bool($debug) ? $debug : filter_var($debug, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE);

        if (null === $isDebug) {
            throw new InvalidConfigurationException(\sprintf('The "ux_sdc.metadata_cache" option is "auto", which follows the "kernel.debug" parameter, but that parameter resolves to %s, which cannot be read as a boolean. Set "ux_sdc.metadata_cache" to true or false.', \is_scalar($debug) ? var_export($debug, true) : get_debug_type($debug)));
        }

        return !$isDebug;
    }

    /**
     * The bundle's own configuration is not processed yet when prepend() runs,
     * so the raw value is read here the way Symfony merges a scalar: last wins.
     *
     * @param array<string, mixed> $config
     */
    private function excludesTemplatesFromAssetMap(ContainerBuilder $container, array $config): bool
    {
        $exclude = $container->resolveEnvPlaceholders($config['exclude_templates_from_asset_map'] ?? true, true);

        // Anything that is not a boolean is rejected by Configuration once
        // load() processes the same value, so it is not interpreted here.
        return false !== $exclude;
    }

    /**
     * Builds the glob that keeps every Twig template of the component
     * directory out of the asset map.
     *
     * FrameworkBundle compiles `excluded_patterns` with
     * Glob::toRegex($glob, true, false), anchored at both ends, and
     * AssetMapperRepository matches it against the absolute path of each file,
     * built from the realpath() of the mapped directory and normalised to
     * forward slashes. That is the same in Symfony 6.4, 7.x and 8.x. Hence:
     *
     *  - the directory goes in as its realpath(), with forward slashes, or a
     *    symlinked or "../"-containing path would never match;
     *  - `*` crosses `/` (strict wildcard slash is off), so one pattern covers
     *    templates in nested component directories too;
     *  - glob metacharacters in the directory name are escaped. `[` and `]`
     *    have no escape in Symfony's Glob (they reach the regex untouched),
     *    so each becomes `?`, which matches any single character;
     *  - `%` is doubled, because the pattern is resolved as a container
     *    parameter once more before FrameworkBundle reads it.
     */
    private static function templateExclusionPattern(string $uxComponentsDir): string
    {
        $directory = realpath($uxComponentsDir);
        if (false === $directory) {
            $directory = $uxComponentsDir;
        }

        $directory = rtrim(str_replace('\\', '/', $directory), '/');
        $directory = strtr($directory, [
            '*' => '\\*',
            '?' => '\\?',
            '{' => '\\{',
            '}' => '\\}',
            '[' => '?',
            ']' => '?',
            '%' => '%%',
        ]);

        return $directory.'/*.twig';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerMetadataResolver(ContainerBuilder $container, array $config): void
    {
        $container->register(ComponentMetadataResolver::class)
            ->setArguments([
                // The roots and the components with an explicit template are set
                // by AssetComponentCompilerPass, which sees those of the application.
                '$twigRoots' => [],
                '$assetRoots' => [],
                '$autoDiscoveryEnabled' => $config['auto_discovery'],
                '$logger' => new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                '$debug' => '%kernel.debug%',
                '$classesWithExplicitTemplate' => [],
            ])
            ->addTag('monolog.logger', ['channel' => 'ux_sdc']);
    }

    /**
     * `component_namespace` with exactly one trailing backslash, the form the
     * class registration and the `defaults` of Twig Components expect.
     */
    private static function normalizeNamespace(string $namespace): string
    {
        return rtrim($namespace, '\\') . '\\';
    }

    private function registerClasses(ContainerBuilder $container, string $namespace, string $resource): void
    {
        $loader = new class ($container, new FileLocator()) extends \Symfony\Component\DependencyInjection\Loader\PhpFileLoader {
            public function doRegister(string $namespace, string $resource): void
            {
                $prototype = (new \Symfony\Component\DependencyInjection\Definition())
                    ->setAutowired(true)
                    ->setAutoconfigured(true);

                $this->registerClasses($prototype, $namespace, $resource);
            }
        };

        $loader->doRegister($namespace, $resource);
    }
    private function isAssetMapperAvailable(ContainerBuilder $container): bool
    {
        if (!interface_exists(AssetMapperInterface::class)) {
            return false;
        }

        // check that FrameworkBundle 6.3 or higher is installed
        if (!$container->hasParameter('kernel.bundles_metadata')) {
            return false;
        }

        $bundlesMetadata = $container->getParameter('kernel.bundles_metadata');
        if (!isset($bundlesMetadata['FrameworkBundle'])) {
            return false;
        }

        return is_file($bundlesMetadata['FrameworkBundle']['path'].'/Resources/config/asset_mapper.php');
    }
}
