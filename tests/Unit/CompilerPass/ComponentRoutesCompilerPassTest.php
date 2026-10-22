<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Unit\CompilerPass;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tito10047\UX\Sdc\CompilerPass\ComponentRoutesCompilerPass;

class ComponentRoutesCompilerPassTest extends TestCase
{
    public function testDisabledNamespaceLeavesRoutingControllers(): void
    {
        $container = $this->containerWithControllers('App\\Component\\');

        (new ComponentRoutesCompilerPass())->process($container);

        $this->assertFalse($container->getDefinition('component')->hasTag('routing.controller'));
        $this->assertTrue($container->getDefinition('component')->hasTag('controller.service_arguments'), 'Only the route registration is withdrawn.');
        $this->assertTrue($container->getDefinition('application')->hasTag('routing.controller'));
        $this->assertTrue($container->getDefinition('lookalike')->hasTag('routing.controller'), 'App\\ComponentX is not in App\\Component.');
    }

    public function testEnabledRoutingChangesNothing(): void
    {
        $container = $this->containerWithControllers(null);

        (new ComponentRoutesCompilerPass())->process($container);

        $this->assertTrue($container->getDefinition('component')->hasTag('routing.controller'));
    }

    private function containerWithControllers(?string $disabledNamespace): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(ComponentRoutesCompilerPass::DISABLED_NAMESPACE_PARAMETER, $disabledNamespace);
        $container->setParameter('component_class', 'App\\Component\\Page\\HomepageAction');

        $container->register('component', '%component_class%')
            ->addTag('controller.service_arguments')
            ->addTag('routing.controller');
        $container->register('application', 'App\\Controller\\HomeController')
            ->addTag('routing.controller');
        $container->register('lookalike', 'App\\ComponentX\\HomeController')
            ->addTag('routing.controller');

        return $container;
    }
}
