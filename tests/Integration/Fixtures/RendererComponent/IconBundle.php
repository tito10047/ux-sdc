<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Integration\Fixtures\RendererComponent;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Registers a component rendered without a template the way symfony/ux-icons
 * registers `UX:Icon` (config/twig_component.php and config/services.php of
 * ux-icons): a `twig.component` service for the class, and a renderer tagged
 * `ux.twig_component.twig_renderer` under the lowercased component name.
 */
final class IconBundle extends AbstractBundle
{
    /**
     * This directory: the default, one level up, would give the bundle the
     * templates of Fixtures/ (as components of its Twig namespace).
     */
    public function getPath(): string
    {
        return __DIR__;
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->services()
            ->set('.test_icons.twig_component.icon', IconComponent::class)
                ->tag('twig.component', ['key' => 'UX:Icon'])
            ->set('.test_icons.twig_icon_runtime', IconRuntime::class)
                ->tag('ux.twig_component.twig_renderer', ['key' => 'ux:icon']);
    }
}
