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

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Tito10047\UX\Sdc\CompilerPass\StimulusControllerPathsCompilerPass;
use Tito10047\UX\Sdc\Runtime\SdcMetadataRegistry;
use Tito10047\UX\Sdc\Service\ComponentMetadataResolver;

class SdcExtension extends Extension implements PrependExtensionInterface
{
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

        $env = $container->hasParameter('kernel.environment') ? $container->getParameter('kernel.environment') : 'prod';
        if ($env === 'dev') {
            $container->removeDefinition('Tito10047\UX\Sdc\EventListener\ComponentRenderListener');
        } else {
            $container->removeDefinition('Tito10047\UX\Sdc\EventListener\DevComponentRenderListener');
        }

        if ($container->hasDefinition('Tito10047\UX\Sdc\EventListener\AssetResponseListener')) {
            $container->getDefinition('Tito10047\UX\Sdc\EventListener\AssetResponseListener')
                ->setArgument('$placeholder', $config['placeholder']);
        }

        if ($container->hasDefinition('Tito10047\UX\Sdc\Twig\AssetExtension')) {
            $container->getDefinition('Tito10047\UX\Sdc\Twig\AssetExtension')
                ->setArgument('$placeholder', $config['placeholder']);
        }

        $namespace = null;
        if (isset($config['component_namespace'])) {
            $namespace = rtrim((string) $config['component_namespace'], '\\') . '\\';
        }
        $container->setParameter('ux_sdc.component_namespace', $namespace);

        if (null !== $namespace) {
            $uxComponentsDir = $container->resolveEnvPlaceholders($config['ux_components_dir'], true);

            if (file_exists($uxComponentsDir)) {
                $this->registerClasses($container, $namespace, $uxComponentsDir);
            }
        }

        // A convenience alias of ux_sdc.ux_components_dir, for applications that
        // want to point their own configuration at the same directory.
        //
        // A parameter, and only a parameter. There used to be a service of this
        // name here too, registered with "string" as its class: nothing ever
        // instantiated it, but `lint:container` walks every definition and died
        // on 'class "string" does not exist', which took away the one command
        // that tells an application whether its container is sound.
        $container->setParameter('app.ui_components.dir', $config['ux_components_dir']);

        if (class_exists(AsLiveComponent::class)) {
            $listeners = [
                'Tito10047\UX\Sdc\EventListener\ComponentRenderListener',
                'Tito10047\UX\Sdc\EventListener\DevComponentRenderListener',
            ];

            foreach ($listeners as $listener) {
                if ($container->hasDefinition($listener)) {
                    $container->getDefinition($listener)
                        ->addTag('kernel.event_listener', [
                            'event' => KernelEvents::CONTROLLER,
                            'method' => 'onKernelController',
                        ]);
                }
            }
        }

        $container->register(SdcMetadataRegistry::class)
            ->setArgument('$cachePath', '%kernel.cache_dir%/sdc_metadata.php')
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

        $container->prependExtensionConfig('twig', [
            'paths' => [
                rtrim($uxComponentsDir, DIRECTORY_SEPARATOR) => null,
                $uxComponentsDir.DIRECTORY_SEPARATOR => 'component',
            ],
        ]);

        $paths = [
            $uxComponentsDir,
        ];
        if ($this->isAssetMapperAvailable($container)) {
            $paths[__DIR__.'/../../assets/controllers'] = '@tito10047/ux-sdc';
        }
        $assetMapper = ['paths' => $paths];
        if ($this->excludesTemplatesFromAssetMap($container, $config)) {
            $assetMapper['excluded_patterns'] = [self::templateExclusionPattern($uxComponentsDir)];
        }
        $container->prependExtensionConfig('framework', [
            'asset_mapper' => $assetMapper,
        ]);

        if (isset($config['component_namespace'])) {
            $namespace = rtrim((string) $config['component_namespace'], '\\') . '\\';
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
        $twigRoots = [];
        if ($container->hasParameter('kernel.project_dir')) {
            $twigRoots[] = '%kernel.project_dir%/src_component';
        }

        if ($container->hasParameter('twig.default_path')) {
            $twigRoots[] = $container->getParameter('twig.default_path');
        }

        $twigRoots[] = $config['ux_components_dir'];

        // V runtime prostredí (Extension::load) nemáme prístup k extension configom iných bundleov tak jednoducho ako v Compiler Pass.
        // Ale pre dev prostredie to väčšinou stačí takto, alebo sa dajú pridať ďalšie cesty.

        $container->register(ComponentMetadataResolver::class)
            ->setArguments([
                $twigRoots,
                $config['auto_discovery'],
            ]);
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
