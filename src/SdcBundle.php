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

use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Tito10047\UX\Sdc\CompilerPass\AssetComponentCompilerPass;
use Tito10047\UX\Sdc\CompilerPass\ComponentRoutesCompilerPass;
use Tito10047\UX\Sdc\CompilerPass\SdcComponentAttributePass;
use Tito10047\UX\Sdc\CompilerPass\StimulusControllerPathsCompilerPass;
use Tito10047\UX\Sdc\DependencyInjection\SdcExtension;

/**
 * The configuration tree lives in {@see DependencyInjection\Configuration},
 * loaded by {@see SdcExtension} under the "ux_sdc" alias.
 *
 * @link https://symfony.com/doc/current/bundles/best_practices.html
 */
class SdcBundle extends Bundle
{
    public const STIMULUS_CONTROLLER = 'tito10047--ux-sdc--sdc-loader';

    public function getContainerExtension(): ?ExtensionInterface
    {
        return new SdcExtension();
    }

    /**
     * The bundle root, not src/: the bundle uses the modern directory structure
     * (templates/, assets/ and config/ next to src/).
     */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new SdcComponentAttributePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, SdcComponentAttributePass::PRIORITY);
        $container->addCompilerPass(new AssetComponentCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, AssetComponentCompilerPass::PRIORITY);
        $container->addCompilerPass(new StimulusControllerPathsCompilerPass());
        $container->addCompilerPass(new ComponentRoutesCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, ComponentRoutesCompilerPass::PRIORITY);
    }
}
