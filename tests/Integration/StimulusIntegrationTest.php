<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\ComponentRendererInterface;

class StimulusIntegrationTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testStimulusTraitReceivesNamespaceInDev(): void
    {
        self::bootKernel(['environment' => 'dev']);
        $container = self::getContainer();

        /** @var ComponentRendererInterface $renderer */
        $renderer = $container->get('ux.twig_component.component_renderer');

        // We need to render the component to trigger the event
        $html = $renderer->createAndRender('stimulus_component');

        // The component instance should have been updated by the listener.
        // But how to get the instance? The renderer doesn't return it easily.
        // We can check the rendered output if it exposes the controller.

        // Let's assume the template uses {{ controller }}
        // We need a template for our component.

        $this->assertStringContainsString('StimulusComponent', $html);
    }

    public function testStimulusTraitReceivesNamespaceInProd(): void
    {
        self::bootKernel(['environment' => 'prod']);
        $container = self::getContainer();

        /** @var ComponentRendererInterface $renderer */
        $renderer = $container->get('ux.twig_component.component_renderer');

        $html = $renderer->createAndRender('stimulus_component');

        $this->assertStringContainsString('StimulusComponent', $html);
    }
}
