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
use Symfony\UX\Icons\IconFinderInterface;

/**
 * Finishes the component icon services once the ux-icons bundle is known.
 */
final class ComponentIconsCompilerPass implements CompilerPassInterface
{
    private const SERVICES = [
        'ux_sdc.icons.registry',
        'ux_sdc.icons.node_visitor',
        'ux_sdc.icons.twig_extension',
        'ux_sdc.icons.finder',
        'ux_sdc.icons.command.import',
    ];

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('ux_sdc.icons.registry')) {
            return;
        }

        // ux-icons is installed, but its bundle is not enabled
        if (!$container->hasDefinition('.ux_icons.local_svg_icon_registry')) {
            foreach (self::SERVICES as $id) {
                $container->removeDefinition($id);
            }

            return;
        }

        if (!$container->hasDefinition('.ux_icons.iconify')) {
            $container->removeDefinition('ux_sdc.icons.command.import');
        }

        // Aliases are resolved by the icon renderer before any registry sees
        // the name, so a bare alias must keep its name to keep working.
        $aliases = [];
        if ($container->hasDefinition('.ux_icons.icon_renderer')) {
            $arguments = $container->getDefinition('.ux_icons.icon_renderer')->getArguments();
            if (\is_array($arguments[2] ?? null)) {
                $aliases = array_map('strval', array_keys($arguments[2]));
            }
        }
        $container->getDefinition('ux_sdc.icons.node_visitor')->replaceArgument(1, $aliases);

        // ux-icons 3 collects the icons to warm from every "ux_icons.finder"
        if (interface_exists(IconFinderInterface::class)) {
            $container->getDefinition('ux_sdc.icons.finder')->addTag('ux_icons.finder');
        } else {
            $container->removeDefinition('ux_sdc.icons.finder');
        }
    }
}
