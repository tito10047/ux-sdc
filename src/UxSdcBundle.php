<?php

namespace Tito10047\UX\Sdc;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Tito10047\UX\Sdc\CompilerPass\AssetComponentCompilerPass;
use Tito10047\UX\Sdc\DependencyInjection\Configuration;

/**
 * @link https://symfony.com/doc/current/bundles/best_practices.html
 */
class UxSdcBundle extends AbstractBundle
{


    public function getContainerExtension(): ?\Symfony\Component\DependencyInjection\Extension\ExtensionInterface
    {
        return new \Tito10047\UX\Sdc\DependencyInjection\SdcExtension();
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->import('../config/definition.php');
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        
        $container->addCompilerPass(new AssetComponentCompilerPass());
    }
}
