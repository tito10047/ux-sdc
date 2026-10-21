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

/**
 * Keeps the classes of component_namespace out of `resource: routing.controllers`
 * when "ux_sdc.routing.enabled" is off.
 *
 * Symfony 7.4+ tags every autoconfigured service with a #[Route] attribute
 * "routing.controller", and that import loads the routes of every tagged class.
 * The bundle registers the component classes as autoconfigured services, so
 * with the option off their routes would still be loaded by any application
 * using the import; the tag is removed instead.
 *
 * Runs after the attributes are autoconfigured (ResolveInstanceofConditionalsPass,
 * priority 100) and before RoutingControllerPass collects the tagged classes
 * (priority 0, registered by FrameworkBundle, hence before any pass of this
 * bundle at the same priority). Nothing to do before Symfony 7.4, where the tag
 * is never set.
 */
final class ComponentRoutesCompilerPass implements CompilerPassInterface
{
    public const PRIORITY = 50;

    /**
     * A build parameter (leading dot): it is not dumped into the compiled container.
     * The namespace whose classes contribute no routes, or null.
     */
    public const DISABLED_NAMESPACE_PARAMETER = '.ux_sdc.routing.disabled_namespace';

    private const TAG = 'routing.controller';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::DISABLED_NAMESPACE_PARAMETER)) {
            return;
        }

        $namespace = $container->getParameter(self::DISABLED_NAMESPACE_PARAMETER);
        if (!\is_string($namespace)) {
            return;
        }

        foreach (array_keys($container->findTaggedServiceIds(self::TAG)) as $id) {
            $definition = $container->getDefinition($id);
            $class = $container->getParameterBag()->resolveValue($definition->getClass());

            if (\is_string($class) && str_starts_with(ltrim($class, '\\'), $namespace)) {
                $definition->clearTag(self::TAG);
            }
        }
    }
}
