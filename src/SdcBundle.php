<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Tito10047\UX\Sdc\CompilerPass\AssetComponentCompilerPass;
use Tito10047\UX\Sdc\CompilerPass\ComponentIconsCompilerPass;
use Tito10047\UX\Sdc\DependencyInjection\Configuration;
use Tito10047\UX\Sdc\DependencyInjection\SdcExtension;

/**
 * @link https://symfony.com/doc/current/bundles/best_practices.html
 */
class SdcBundle extends AbstractBundle
{
    public const STIMULUS_CONTROLLER = 'tito10047--ux-sdc--sdc-loader';

    public function getContainerExtension(): ?ExtensionInterface
    {
        return new SdcExtension();
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->import('../config/definition.php');
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new AssetComponentCompilerPass());
        // before TwigBundle collects the "twig.extension" tags
        $container->addCompilerPass(new ComponentIconsCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 100);
    }
}
