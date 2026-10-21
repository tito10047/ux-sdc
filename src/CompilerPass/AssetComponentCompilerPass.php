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
use Tito10047\UX\Sdc\Runtime\SdcMetadataRegistry;
use Tito10047\UX\Sdc\Service\ComponentMetadataResolver;

/**
 * @phpstan-import-type SdcMetadata from ComponentMetadataResolver
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

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(SdcMetadataRegistry::class)) {
            return;
        }

        $registryDefinition = $container->getDefinition(SdcMetadataRegistry::class);
        $cachePath = $container->getParameterBag()->resolveValue($registryDefinition->getArgument('$cachePath'));

        if (true !== $container->getParameter(self::METADATA_CACHE_PARAMETER)) {
            // The metadata is resolved on render. A file left behind by an earlier
            // build with the cache on would still be read by SdcMetadataRegistry,
            // which stays available, and describe components as they were then.
            if (file_exists($cachePath)) {
                unlink($cachePath);
            }

            return;
        }

        // Validated as a boolean node by the bundle configuration.
        $autoDiscovery = (bool) $container->getParameter('ux_sdc.auto_discovery');
        $twigRoots = $this->collectTwigRoots($container);

        $resolver = new ComponentMetadataResolver($twigRoots, $autoDiscovery);
        $componentAssets = $this->processTaggedServices($container, $resolver);

        $this->dumpCache($cachePath, $componentAssets);
    }

    /**
     * @return list<string> existing directories, as real paths
     */
    private function collectTwigRoots(ContainerBuilder $container): array
    {
        $twigRoots = [];
        if ($container->hasParameter('kernel.project_dir')) {
            $defaultDir = $container->getParameterBag()->resolveValue('%kernel.project_dir%/src_component');
            if (is_dir($defaultDir)) {
                $twigRoots[] = $defaultDir;
            }
        }

        if ($container->hasParameter('twig.default_path')) {
            $twigRoots[] = $container->getParameterBag()->resolveValue($container->getParameter('twig.default_path'));
        }

        if ($container->hasParameter('ux_sdc.ux_components_dir')) {
            $uxDir = $container->getParameterBag()->resolveValue($container->getParameter('ux_sdc.ux_components_dir'));
            if (is_dir($uxDir)) {
                $twigRoots[] = $uxDir;
            }
        }

        // Spracovanie twig paths
        if ($container->hasExtension('twig')) {
            $twigConfigs = $container->getExtensionConfig('twig');
            foreach ($twigConfigs as $config) {
                if (isset($config['paths'])) {
                    foreach ($config['paths'] as $path => $namespace) {
                        // "paths" is either a path => namespace map or a plain list of paths.
                        $actualPath = is_numeric($path) ? $namespace : $path;
                        $twigRoots[] = $container->getParameterBag()->resolveValue($actualPath);
                    }
                }
            }
        }

        $realRoots = [];
        foreach ($twigRoots as $root) {
            if (!\is_string($root) || '' === $root) {
                continue;
            }

            $realRoot = realpath($root);
            if (false !== $realRoot) {
                $realRoots[] = $realRoot;
            }
        }

        return array_values(array_unique($realRoots));
    }

    /**
     * @return SdcMetadata
     */
    private function processTaggedServices(ContainerBuilder $container, ComponentMetadataResolver $resolver): array
    {
        $componentAssets = [];
        $taggedServices = $container->findTaggedServiceIds('twig.component');

        foreach ($taggedServices as $id => $tags) {
            $definition = $container->getDefinition($id);
            $class = $definition->getClass();

            if (!$class || !class_exists($class)) {
                continue;
            }

            $assets = $resolver->resolveMetadata($class, $class, $componentAssets);

            if (!empty($assets)) {
                $componentAssets[$class] = $assets;
            }
        }

        return $componentAssets;
    }

    /**
     * @param SdcMetadata $data
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
