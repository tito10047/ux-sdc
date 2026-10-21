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
    public function testGetControllerThrowsOutsideTheNamespace(): void
    {
        $component = new TestComponent();
        $component->setComponentNamespace('App\\Component\\');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot name the Stimulus controller of "Tito10047\\UX\\Sdc\\Tests\\Unit\\Twig\\TestComponent": the class is not in the component namespace "App\\Component\\"');

        $component->getController();
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

    public function testGetControllerWithNamespaceWithoutTrailingBackslash(): void
    {
        $component = new Sub\SubComponent();
        $component->setComponentNamespace('Tito10047\\UX\\Sdc\\Tests\\Unit');

        $this->assertSame('Twig--Sub--SubComponent', $component->getController());
    }

    /**
     * The name is that of the class using the trait, as it always was: a
     * subclass shares the controller of its parent.
     */
    public function testGetControllerOfSubclassNamesTheClassUsingTheTrait(): void
    {
        $component = new Sub\ExtendedComponent();
        $component->setComponentNamespace('Tito10047\\UX\\Sdc\\Tests\\Unit\\Twig\\');

        $this->assertSame('Sub--SubComponent', $component->getController());
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

class ExtendedComponent extends SubComponent
{
}
