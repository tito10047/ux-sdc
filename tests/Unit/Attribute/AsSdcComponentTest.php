<?php

namespace Tito10047\UX\Sdc\Tests\Unit\Attribute;

use PHPUnit\Framework\TestCase;
use Tito10047\UX\Sdc\Attribute\AsSdcComponent;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

class AsSdcComponentTest extends TestCase
{
    public function testInstantiate(): void
    {
        $attribute = new AsSdcComponent(
            name: 'my_component',
            template: 'components/my_component.html.twig',
            css: 'css/style.css',
            js: 'js/script.js',
            exposePublicProps: false,
            attributesVar: 'attrs'
        );

        $this->assertInstanceOf(AsTwigComponent::class, $attribute);
        $this->assertSame('my_component', $attribute->serviceConfig()['key']);
        $this->assertSame('components/my_component.html.twig', $attribute->serviceConfig()['template']);
        $this->assertSame('css/style.css', $attribute->css);
        $this->assertSame('js/script.js', $attribute->js);
    }

    public function testDefaultValues(): void
    {
        $attribute = new AsSdcComponent('my_component');

        $this->assertSame('my_component', $attribute->serviceConfig()['key']);
        $this->assertNull($attribute->css);
        $this->assertNull($attribute->js);
    }
}
