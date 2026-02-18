<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Unit\Attribute;

use PHPUnit\Framework\TestCase;
use Tito10047\UX\Sdc\Attribute\AsSdcLiveComponent;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;

class AsSdcLiveComponentTest extends TestCase
{
    public function testInstantiate(): void
    {
        $attribute = new AsSdcLiveComponent(
            name: 'my_live_component',
            template: 'components/my_live_component.html.twig',
            css: 'css/style.css',
            js: 'js/script.js',
            exposePublicProps: false,
            attributesVar: 'attrs'
        );

        $this->assertInstanceOf(AsLiveComponent::class, $attribute);
        $this->assertSame('my_live_component', $attribute->serviceConfig()['key']);
        $this->assertSame('components/my_live_component.html.twig', $attribute->serviceConfig()['template']);
        $this->assertSame('css/style.css', $attribute->css);
        $this->assertSame('js/script.js', $attribute->js);
    }

    public function testDefaultValues(): void
    {
        $attribute = new AsSdcLiveComponent('my_live_component');

        $this->assertSame('my_live_component', $attribute->serviceConfig()['key']);
        $this->assertNull($attribute->css);
        $this->assertNull($attribute->js);
    }
}
