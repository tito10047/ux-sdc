<?php

namespace Tito10047\UX\TwigComponentSdc;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Tito10047\UX\TwigComponentSdc\CompilerPass\AssetComponentCompilerPass;
use Tito10047\UX\TwigComponentSdc\DependencyInjection\Configuration;

/**
 * @link https://symfony.com/doc/current/bundles/best_practices.html
 */
class TwigComponentSdcBundle extends AbstractBundle implements PrependExtensionInterface
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->import('../config/definition.php');
    }

    public function prepend(ContainerBuilder $builder): void
    {
        $configs = $builder->getExtensionConfig('twig_component_sdc');

        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs, $builder);

        $uxComponentsDir = $builder->resolveEnvPlaceholders($config['ux_components_dir'], true);

        $builder->prependExtensionConfig('twig', [
            'paths' => [
                $uxComponentsDir => null,
            ],
        ]);

        $builder->prependExtensionConfig('framework', [
            'asset_mapper' => [
                'paths' => [
                    $uxComponentsDir,
                ],
            ],
        ]);

        if (null !== $config['component_namespace']) {
            $builder->prependExtensionConfig('twig_component', [
                'defaults' => [
                    rtrim($config['component_namespace'], '\\') . '\\' => [
                        'template_directory' => '',
                        'directory' => $uxComponentsDir,
                    ],
                ],
            ]);
        }

        if ($config['stimulus']['enabled'] && $builder->hasExtension('stimulus')) {
            $builder->prependExtensionConfig('stimulus', [
                'controller_paths' => [
                    $uxComponentsDir,
                ],
            ]);
        }
    }

    private function processConfiguration(Configuration $configuration, array $configs, ContainerBuilder $container): array
    {
        $processor = new \Symfony\Component\Config\Definition\Processor();
        return $processor->processConfiguration($configuration, $configs);
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');

        if ($builder->hasDefinition('Tito10047\UX\TwigComponentSdc\EventListener\AssetResponseListener')) {
            $builder->getDefinition('Tito10047\UX\TwigComponentSdc\EventListener\AssetResponseListener')
                ->setArgument('$placeholder', $config['placeholder']);
        }

        if ($builder->hasDefinition('Tito10047\UX\TwigComponentSdc\Twig\AssetExtension')) {
            $builder->getDefinition('Tito10047\UX\TwigComponentSdc\Twig\AssetExtension')
                ->setArgument('$placeholder', $config['placeholder']);
        }

        $builder->setParameter('twig_component_sdc.auto_discovery', $config['auto_discovery']);
        $builder->setParameter('twig_component_sdc.ux_components_dir', $config['ux_components_dir']);

        $namespace = null;
        if (null !== $config['component_namespace']) {
            $namespace = rtrim($config['component_namespace'], '\\') . '\\';
        }
        $builder->setParameter('twig_component_sdc.component_namespace', $namespace);

        if (null !== $namespace) {
            $uxComponentsDir = $builder->resolveEnvPlaceholders($config['ux_components_dir'], true);

            if (file_exists($uxComponentsDir)) {
                $this->registerClasses($builder, $namespace, $uxComponentsDir);
            }
        }

        $builder->setAlias('app.ui_components.dir', 'twig_component_sdc.ux_components_dir');
        $builder->setParameter('app.ui_components.dir', $config['ux_components_dir']);
    }

    private function registerClasses(ContainerBuilder $container, string $namespace, string $resource): void
    {
        $loader = new class ($container, new \Symfony\Component\Config\FileLocator()) extends \Symfony\Component\DependencyInjection\Loader\PhpFileLoader {
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

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new AssetComponentCompilerPass());
    }
}
