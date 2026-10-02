<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tito10047\UX\Sdc\DependencyInjection\SdcExtension;

/**
 * Guards what `lint:container` checks in an application that installs this
 * bundle: every definition this extension adds has to name a class that really
 * exists.
 *
 * The extension used to register `ux_sdc.ux_components_dir` as a service with
 * "string" for a class. Nothing instantiated it, so the application ran — but
 * `lint:container` walks every definition and stopped on
 * 'class "string" does not exist', and an application that cannot lint its
 * container has lost the tool that tells it the container is sound.
 */
class SdcExtensionDefinitionsTest extends TestCase
{
    public function testEveryDefinitionNamesARealClass(): void
    {
        $container = $this->buildContainer();

        $this->assertNotEmpty($container->getDefinitions(), 'The extension registered nothing at all.');

        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass();

            if (null === $class || $definition->isSynthetic() || $definition->isAbstract()) {
                continue;
            }

            // Definitions whose class comes from a parameter are resolved later.
            if (str_contains($class, '%')) {
                continue;
            }

            $this->assertTrue(
                class_exists($class) || interface_exists($class),
                \sprintf('Service "%s" is defined with class "%s", which does not exist.', $id, $class)
            );
        }
    }

    public function testNoAliasDanglesOverNothing(): void
    {
        $container = $this->buildContainer();

        $dangling = [];
        foreach ($container->getAliases() as $id => $alias) {
            $target = (string) $alias;

            if (!$container->hasDefinition($target) && !$container->hasAlias($target)) {
                $dangling[] = \sprintf('%s -> %s', $id, $target);
            }
        }

        $this->assertSame([], $dangling, 'These aliases point at services that are not defined.');
    }

    public function testTheComponentsDirectoryIsAParameterAndNotAService(): void
    {
        $container = $this->buildContainer();

        $this->assertTrue($container->hasParameter('ux_sdc.ux_components_dir'));
        $this->assertTrue($container->hasParameter('app.ui_components.dir'));

        $this->assertFalse(
            $container->hasDefinition('ux_sdc.ux_components_dir'),
            'A directory is a parameter; registering it as a service breaks lint:container.'
        );
        $this->assertFalse($container->hasAlias('app.ui_components.dir'));
    }

    private function buildContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', realpath(__DIR__ . '/../../..'));
        $container->setParameter('kernel.environment', 'test');

        (new SdcExtension())->load([[
            'auto_discovery' => true,
            'ux_components_dir' => '%kernel.project_dir%/tests/Visual/Generated',
            'component_namespace' => 'Tito10047\\UX\\Sdc\\Tests\\Visual\\Generated',
            'placeholder' => '<!-- __UX_TWIG_COMPONENT_ASSETS__ -->',
            'stimulus' => ['enabled' => true],
        ]], $container);

        return $container;
    }
}
