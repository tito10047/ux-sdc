<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\CompilerPass;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\Filesystem\Filesystem;
use Tito10047\UX\Sdc\Debug\ComponentInspector;
use Tito10047\UX\Sdc\Runtime\SdcMetadataRegistry;
use Tito10047\UX\Sdc\Service\ComponentMetadataResolver;

/**
 * Collects what the resolver needs to know about the application — its Twig
 * paths, its AssetMapper paths and the components registered with an explicit
 * template — for the runtime resolver and for the build-time one, hands the
 * components to `debug:sdc`, and, with
 * `ux_sdc.metadata_cache` on, dumps the metadata of every component into
 * `sdc_metadata.php`.
 *
 * The paths are read from the services they configure, after every extension
 * has loaded: the Twig filesystem loader and the AssetMapper repository. That
 * is the list each of them actually uses — `twig.default_path`, `twig.paths`,
 * bundle templates, `framework.asset_mapper.paths` and bundle public
 * directories, normalised and merged — rather than a second reading of the
 * raw configuration.
 *
 * @phpstan-import-type SdcMetadataCache from SdcMetadataRegistry
 */
final class AssetComponentCompilerPass implements CompilerPassInterface
{
    /**
     * Whether the metadata is resolved at build time, as resolved by SdcExtension
     * from `ux_sdc.metadata_cache`: always a boolean.
     *
     * A build parameter (leading dot): it is not dumped into the compiled container.
     */
    public const METADATA_CACHE_PARAMETER = '.ux_sdc.metadata_cache';

    private const TWIG_LOADER = 'twig.loader.native_filesystem';
    private const ASSET_MAPPER_REPOSITORY = 'asset_mapper.repository';

    public function process(ContainerBuilder $container): void
    {
        $twigRoots = $this->collectTwigRoots($container);
        $assetRoots = $this->collectAssetMapperRoots($container);
        $componentClasses = $this->collectComponentClasses($container);
        $classesWithExplicitTemplate = $this->collectClassesWithExplicitTemplate($container);

        // Both pipelines resolve against the same roots and registrations. Only
        // a compiler pass sees them complete, which is why the runtime resolver
        // gets them from here and not from SdcExtension.
        if ($container->hasDefinition(ComponentMetadataResolver::class)) {
            $container->getDefinition(ComponentMetadataResolver::class)
                ->setArgument('$twigRoots', $twigRoots)
                ->setArgument('$assetRoots', $assetRoots)
                ->setArgument('$classesWithExplicitTemplate', $classesWithExplicitTemplate);
        }

        if ($container->hasDefinition(ComponentInspector::class)) {
            $container->getDefinition(ComponentInspector::class)
                ->setArgument('$componentClasses', $componentClasses)
                ->setArgument('$classesWithExplicitTemplate', $classesWithExplicitTemplate);
        }

        if (!$container->hasDefinition(SdcMetadataRegistry::class)) {
            return;
        }

        $registryDefinition = $container->getDefinition(SdcMetadataRegistry::class);
        $cachePath = $container->getParameterBag()->resolveValue($registryDefinition->getArgument('$cachePath'));

        if (true !== $container->getParameter(self::METADATA_CACHE_PARAMETER)) {
            // The metadata is resolved on render. A file left behind by an earlier
            // build with the cache on would describe components as they were then;
            // the registry does not read it, but nothing should be left to mislead.
            if (file_exists($cachePath)) {
                unlink($cachePath);
            }

            return;
        }

        // Validated as a boolean node by the bundle configuration.
        $autoDiscovery = (bool) $container->getParameter('ux_sdc.auto_discovery');

        // Files the resolver has to leave out are reported to the compiler log,
        // the build-time counterpart of the `logger` service, which does not
        // exist yet. The kernel keeps that log for debug containers only (and
        // the profiler shows it), so the resolver is told it runs in debug: in
        // a production build the report goes nowhere without any further check.
        $resolver = new ComponentMetadataResolver(
            $twigRoots,
            $assetRoots,
            $autoDiscovery,
            new CompilerLogLogger($container, $this),
            true,
            $classesWithExplicitTemplate,
        );

        $this->dumpCache($cachePath, $this->resolveComponents($componentClasses, $resolver));
    }

    /**
     * The directories of the Twig filesystem loader, in the order Twig looks
     * templates up, as registered by TwigBundle: `addPath($path)` for the main
     * namespace, `addPath($path, $namespace)` for any other.
     *
     * @return array<string, string|null> existing directories, as real paths, => namespace (null for the main one)
     */
    private function collectTwigRoots(ContainerBuilder $container): array
    {
        if (!$container->hasDefinition(self::TWIG_LOADER)) {
            return [];
        }

        $roots = [];
        foreach ($container->getDefinition(self::TWIG_LOADER)->getMethodCalls() as [$method, $arguments]) {
            if ('addPath' !== $method || !isset($arguments[0]) || !\is_string($arguments[0])) {
                continue;
            }

            $namespace = isset($arguments[1]) && \is_string($arguments[1]) && '' !== $arguments[1] ? $arguments[1] : null;
            $realPath = $this->resolveDirectory($container, $arguments[0]);

            // `ux_sdc.ux_components_dir` is registered twice, in the main
            // namespace and as @component: the main namespace is kept, so its
            // templates keep the names they have always had.
            if (null !== $realPath && (!\array_key_exists($realPath, $roots) || null === $namespace)) {
                $roots[$realPath] = $namespace;
            }
        }

        return $roots;
    }

    /**
     * The directories of the AssetMapper repository, in the order AssetMapper
     * looks assets up. Its first constructor argument has been the map of
     * directory => namespace FrameworkBundle builds from
     * `framework.asset_mapper.paths` since AssetMapper exists.
     *
     * @return array<string, string> existing directories, as real paths, => namespace ('' for none)
     */
    private function collectAssetMapperRoots(ContainerBuilder $container): array
    {
        // Absent when AssetMapper is disabled.
        if (!$container->hasDefinition(self::ASSET_MAPPER_REPOSITORY)) {
            return [];
        }

        $arguments = $container->getDefinition(self::ASSET_MAPPER_REPOSITORY)->getArguments();
        $paths = $arguments[0] ?? $arguments['$paths'] ?? null;
        if (!\is_array($paths)) {
            throw new LogicException(\sprintf('UX SDC cannot read the AssetMapper paths: the first argument of the "%s" service is not the array of paths it expects (%s). This version of AssetMapper is not supported.', self::ASSET_MAPPER_REPOSITORY, get_debug_type($paths)));
        }

        $roots = [];
        foreach ($paths as $path => $namespace) {
            if (!\is_string($path) || !\is_string($namespace)) {
                continue;
            }

            $realPath = $this->resolveDirectory($container, $path);
            if (null !== $realPath && !\array_key_exists($realPath, $roots)) {
                $roots[$realPath] = $namespace;
            }
        }

        return $roots;
    }

    /**
     * Components whose registration names their template: the `template`
     * argument of #[AsTwigComponent] and its subclasses, or of a `twig.component`
     * tag registered by hand. TwigComponent only fills in a default template
     * for a tag that names none, and does so in its own copy of the tag, so the
     * tags still say what the application chose.
     *
     * @return list<string>
     */
    private function collectClassesWithExplicitTemplate(ContainerBuilder $container): array
    {
        $classes = [];

        foreach ($container->findTaggedServiceIds('twig.component') as $id => $tags) {
            foreach ($tags as $tag) {
                if (!isset($tag['template']) && !isset($tag['template_from_method'])) {
                    continue;
                }

                $class = $container->getParameterBag()->resolveValue($container->getDefinition($id)->getClass());
                if (\is_string($class)) {
                    $classes[] = $class;
                }

                break;
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * Resolves a configured directory the way Twig and AssetMapper do: relative
     * to the project directory unless absolute.
     *
     * @return string|null the real path; null when the directory does not exist
     */
    private function resolveDirectory(ContainerBuilder $container, string $path): ?string
    {
        $parameterBag = $container->getParameterBag();
        $resolved = $container->resolveEnvPlaceholders($parameterBag->unescapeValue($parameterBag->resolveValue($path)), true);
        if (!\is_string($resolved) || '' === $resolved) {
            return null;
        }

        if (!(new Filesystem())->isAbsolutePath($resolved) && $container->hasParameter('kernel.project_dir')) {
            $projectDir = $container->getParameter('kernel.project_dir');
            if (\is_string($projectDir)) {
                $resolved = $projectDir . '/' . $resolved;
            }
        }

        $realPath = realpath($resolved);

        return false !== $realPath && is_dir($realPath) ? $realPath : null;
    }

    /**
     * The classes of the `twig.component` services that exist.
     *
     * @return list<string>
     */
    private function collectComponentClasses(ContainerBuilder $container): array
    {
        $classes = [];

        foreach (array_keys($container->findTaggedServiceIds('twig.component')) as $id) {
            $class = $container->getParameterBag()->resolveValue($container->getDefinition($id)->getClass());

            if (\is_string($class) && class_exists($class)) {
                $classes[] = $class;
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * @param list<string> $componentClasses
     *
     * @return SdcMetadataCache
     */
    private function resolveComponents(array $componentClasses, ComponentMetadataResolver $resolver): array
    {
        $metadata = [];

        foreach ($componentClasses as $class) {
            $componentMetadata = $resolver->resolve($class);

            if (!$componentMetadata->isEmpty()) {
                $metadata[$class] = [
                    'assets' => $componentMetadata->assets,
                    'template' => $componentMetadata->template,
                    'problems' => $componentMetadata->problems,
                ];
            }
        }

        return $metadata;
    }

    /**
     * @param SdcMetadataCache $data
     */
    private function dumpCache(string $path, array $data): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $content = '<?php return ' . var_export($data, true) . ';';
        file_put_contents($path, $content);
    }
}
