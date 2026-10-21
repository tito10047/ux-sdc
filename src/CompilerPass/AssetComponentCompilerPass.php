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
 * Resolves the Twig paths co-located files are looked up in, for the runtime
 * resolver and for the build-time one, and — with `ux_sdc.metadata_cache` on —
 * dumps the metadata of every component into `sdc_metadata.php`.
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

    public function process(ContainerBuilder $container): void
    {
        $twigRoots = $this->collectTwigRoots($container);

        // Both pipelines look files up in the same Twig paths. Only a compiler
        // pass sees the `twig.paths` of the application, which is why the
        // runtime resolver gets them from here and not from SdcExtension.
        if ($container->hasDefinition(ComponentMetadataResolver::class)) {
            $container->getDefinition(ComponentMetadataResolver::class)->setArgument('$twigRoots', $twigRoots);
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
        $resolver = new ComponentMetadataResolver($twigRoots, $autoDiscovery, new CompilerLogLogger($container, $this), true);

        $this->dumpCache($cachePath, $this->resolveTaggedComponents($container, $resolver));
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
     * @return SdcMetadataCache
     */
    private function resolveTaggedComponents(ContainerBuilder $container, ComponentMetadataResolver $resolver): array
    {
        $metadata = [];
        $taggedServices = $container->findTaggedServiceIds('twig.component');

        foreach ($taggedServices as $id => $tags) {
            $definition = $container->getDefinition($id);
            $class = $definition->getClass();

            if (!$class || !class_exists($class)) {
                continue;
            }

            $componentMetadata = $resolver->resolve($class);

            if (!$componentMetadata->isEmpty()) {
                $metadata[$class] = [
                    'assets' => $componentMetadata->assets,
                    'template' => $componentMetadata->template,
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
