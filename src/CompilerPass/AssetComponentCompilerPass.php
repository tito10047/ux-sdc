<?php

namespace Tito10047\UX\TwigComponentSdc\CompilerPass;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Tito10047\UX\TwigComponentSdc\Attribute\Asset;
use Tito10047\UX\TwigComponentSdc\Attribute\AsSdcComponent;
use Tito10047\UX\TwigComponentSdc\Dto\ComponentAssetMap;
use ReflectionClass;

final class AssetComponentCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('Tito10047\UX\TwigComponentSdc\Dto\ComponentAssetMap')) {
            return;
        }

        $autoDiscovery = $container->getParameter('twig_component_sdc.auto_discovery');

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

        if ($container->hasParameter('twig_component_sdc.ux_components_dir')) {
            $uxDir = $container->getParameterBag()->resolveValue($container->getParameter('twig_component_sdc.ux_components_dir'));
            if (is_dir($uxDir)) {
                $twigRoots[] = realpath($uxDir);
            }
        }

        // Spracovanie twig paths (ak sú dostupné v konfigurácii bundle-u)
        if ($container->hasExtension('twig')) {
            $twigConfigs = $container->getExtensionConfig('twig');
            foreach ($twigConfigs as $config) {
                if (isset($config['paths'])) {
                    foreach ($config['paths'] as $path => $namespace) {
                        // $path môže byť string alebo pole v novších verziách
                        $actualPath = is_array($path) ? key($path) : $path;
                        if (is_numeric($actualPath)) {
                            $actualPath = $namespace;
                        } // Ak nie je namespace, cesta je v hodnote

                        $twigRoots[] = $container->getParameterBag()->resolveValue($actualPath);
                    }
                }
            }
        }

        // Normalizácia a odstránenie duplicít
        $twigRoots = array_unique(array_map('realpath', array_filter($twigRoots)));

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

            $reflectionClass = new ReflectionClass($class);
            $assets = [];

            // 1. Čítanie atribútov #[Asset]
            $attributes = $reflectionClass->getAttributes(Asset::class);
            foreach ($attributes as $attribute) {
                /** @var Asset $asset */
                $asset = $attribute->newInstance();

                if ($asset->path) {
                    $assets[] = [
                        'path' => $asset->path,
                        'type' => $asset->type ?? '',
                        'priority' => $asset->priority,
                        'attributes' => $asset->attributes,
                    ];
                }
            }

            // 1b. Čítanie atribútu #[AsSdcComponent]
            $sdcAttributes = $reflectionClass->getAttributes(AsSdcComponent::class);
            foreach ($sdcAttributes as $attribute) {
                /** @var AsSdcComponent $sdcComponent */
                $sdcComponent = $attribute->newInstance();

                if ($sdcComponent->css) {
                    $assets[] = [
                        'path' => $sdcComponent->css,
                        'type' => 'css',
                        'priority' => 0,
                        'attributes' => [],
                    ];
                }

                if ($sdcComponent->js) {
                    $assets[] = [
                        'path' => $sdcComponent->js,
                        'type' => 'js',
                        'priority' => 0,
                        'attributes' => [],
                    ];
                }
            }

            // 2. Auto-discovery (ak je zapnuté)
            if ($autoDiscovery) {
                $dir = dirname($reflectionClass->getFileName());
                $baseName = $reflectionClass->getShortName();

                // CSS a JS auto-discovery
                foreach (['css', 'js'] as $ext) {
                    $assetFile = $dir . DIRECTORY_SEPARATOR . $baseName . '.' . $ext;
                    if (file_exists($assetFile)) {
                        $assets[] = [
                            'path' => $baseName . '.' . $ext,
                            'type' => $ext,
                            'priority' => 0,
                            'attributes' => [],
                        ];
                    }
                }

                // Twig template auto-discovery
                $twigFile = realpath($dir . DIRECTORY_SEPARATOR . $baseName . '.html.twig');
                if ($twigFile && file_exists($twigFile)) {
                    $shortestPath = null;

                    // error_log("Found twig file: " . $twigFile);
                    // error_log("Twig roots: " . print_r($twigRoots, true));

                    foreach ($twigRoots as $root) {
                        if (str_starts_with($twigFile, $root)) {
                            $relativePath = ltrim(substr($twigFile, strlen($root)), DIRECTORY_SEPARATOR);
                            if ($shortestPath === null || strlen($relativePath) < strlen($shortestPath)) {
                                $shortestPath = $relativePath;
                            }
                        }
                    }

                    if ($shortestPath) {
                        // error_log("Shortest path: " . $shortestPath);
                        $componentAssets[$componentName . '_template'] = $shortestPath;
                    }
                }
            }

            if (!empty($assets)) {
                $componentAssets[$componentName] = $assets;
            }
        }

        $container->getDefinition('Tito10047\UX\TwigComponentSdc\Dto\ComponentAssetMap')
            ->setArgument('$map', $componentAssets);
    }
}
