<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Unit\Twig;

use PHPUnit\Framework\TestCase;
use Tito10047\UX\Sdc\Twig\Stimulus;
use Tito10047\UX\Sdc\Twig\ComponentNamespaceInterface;

class StimulusTest extends TestCase
{
    public function testGetControllerReturnsFormattedName(): void
    {
        $component = new class () implements ComponentNamespaceInterface {
            use Stimulus;
        };

        $component->setComponentNamespace('Tito10047\\UX\\Sdc\\Tests\\Unit\\Twig\\');

        // The class name of the anonymous class will be something like
        // Tito10047\UX\Sdc\Tests\Unit\Twig\StimulusTest@anonymous...
        // We want to verify it replaces the namespace and backslashes

        $controller = $component->getController();
        $this->assertStringNotContainsString('Tito10047--UX--Sdc--Tests--Twig--', $controller);
        $this->assertStringContainsString('StimulusTest', $controller);
    }

    public function testGetControllerThrowsExceptionIfNamespaceNotSet(): void
    {
        $component = new class () {
            use Stimulus;
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Component namespace is not set. Did you forget to implement ComponentNamespaceInterface?');

        $component->getController();
    }

    public function testGetControllerWithSpecificClass(): void
    {
        $component = new TestComponent();
        $component->setComponentNamespace('Tito10047\\UX\\Sdc\\Tests\\Unit\\Twig\\');

        $this->assertEquals('TestComponent', $component->getController());
    }

    public function testGetControllerWithSubNamespace(): void
    {
        $component = new Sub\SubComponent();
        $component->setComponentNamespace('Tito10047\\UX\\Sdc\\Tests\\Unit\\Twig\\');

        $this->assertEquals('Sub--SubComponent', $component->getController());
    }
}

class TestComponent implements ComponentNamespaceInterface
{
    use Stimulus;
}

namespace Tito10047\UX\Sdc\Tests\Unit\Twig\Sub;

use Tito10047\UX\Sdc\Twig\ComponentNamespaceInterface;
use Tito10047\UX\Sdc\Twig\Stimulus;

class SubComponent implements ComponentNamespaceInterface
{
    use Stimulus;
}
