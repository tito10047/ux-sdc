<?php

namespace Tito10047\UX\Sdc\CompilerPass;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Tito10047\UX\Sdc\Attribute\Asset;
use Tito10047\UX\Sdc\Attribute\AsSdcComponent;
use Tito10047\UX\Sdc\Runtime\SdcMetadataRegistry;
use Tito10047\UX\Sdc\Service\ComponentMetadataResolver;
use ReflectionClass;

final class AssetComponentCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(SdcMetadataRegistry::class)) {
            return;
        }

        $registryDefinition = $container->getDefinition(SdcMetadataRegistry::class);
        $cachePath = $container->getParameterBag()->resolveValue($registryDefinition->getArgument('$cachePath'));

        $env = $container->hasParameter('kernel.environment') ? $container->getParameter('kernel.environment') : 'test';
        if ($env === 'dev') {
            if (file_exists($cachePath)) {
                unlink($cachePath);
            }

            return;
        }

        $autoDiscovery = $container->getParameter('ux_sdc.auto_discovery');
        $twigRoots = $this->collectTwigRoots($container);
        
        $resolver = new ComponentMetadataResolver($twigRoots, $autoDiscovery);
        $componentAssets = $this->processTaggedServices($container, $resolver);

        $this->dumpCache($cachePath, $componentAssets);
    }

    private function collectTwigRoots(ContainerBuilder $container): array
    {
        $twigRoots = [];
        if ($container->hasParameter('kernel.project_dir')) {
            $defaultDir = $container->getParameterBag()->resolveValue('%kernel.project_dir%/src_component');
            if (is_dir($defaultDir)) {
                $twigRoots[] = realpath($defaultDir);
            }
        }

        if ($container->hasParameter('twig.default_path')) {
            $twigRoots[] = $container->getParameterBag()->resolveValue($container->getParameter('twig.default_path'));
        }

        if ($container->hasParameter('ux_sdc.ux_components_dir')) {
            $uxDir = $container->getParameterBag()->resolveValue($container->getParameter('ux_sdc.ux_components_dir'));
            if (is_dir($uxDir)) {
                $twigRoots[] = realpath($uxDir);
            }
        }

        // Spracovanie twig paths
        if ($container->hasExtension('twig')) {
            $twigConfigs = $container->getExtensionConfig('twig');
            foreach ($twigConfigs as $config) {
                if (isset($config['paths'])) {
                    foreach ($config['paths'] as $path => $namespace) {
                        $actualPath = is_array($path) ? key($path) : $path;
                        if (is_numeric($actualPath)) {
                            $actualPath = $namespace;
                        }
                        $twigRoots[] = $container->getParameterBag()->resolveValue($actualPath);
                    }
                }
            }
        }

        return array_unique(array_map('realpath', array_filter($twigRoots)));
    }

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

            $componentName = null;
            foreach ($tags as $tag) {
                if (isset($tag['key'])) {
                    $componentName = $tag['key'];
                    break;
                }
            }

            if (!$componentName) {
                continue;
            }

            $assets = $resolver->resolveMetadata($class, $componentName, $componentAssets);

            if (!empty($assets)) {
                $componentAssets[$componentName] = $assets;
            }
        }

        return $componentAssets;
    }

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
