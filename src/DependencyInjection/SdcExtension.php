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

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
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

        $container->setParameter('ux_sdc.auto_discovery', $config['auto_discovery']);
        $container->setParameter('ux_sdc.ux_components_dir', $config['ux_components_dir']);
        $container->register('ux_sdc.ux_components_dir', 'string')
            ->setPublic(true);

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

        $container->setAlias('app.ui_components.dir', 'ux_sdc.ux_components_dir');
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
                $uxComponentsDir => null,
            ],
        ]);

        $container->prependExtensionConfig('framework', [
            'asset_mapper' => [
                'paths' => [
                    $uxComponentsDir,
                ],
            ],
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

        if (($config['stimulus']['enabled'] ?? true) && $container->hasExtension('stimulus')) {
            $container->prependExtensionConfig('stimulus', [
                'controller_paths' => [
                    $uxComponentsDir,
                ],
            ]);
        }
    }

    private function registerMetadataResolver(ContainerBuilder $container, array $config): void
    {
        $twigRoots = [];
        if ($container->hasParameter('kernel.project_dir')) {
            $twigRoots[] = $container->getParameter('kernel.project_dir') . '/src_component';
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
}
