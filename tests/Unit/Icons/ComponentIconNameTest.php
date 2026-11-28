<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Unit\Icons;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tito10047\UX\Sdc\Icons\ComponentIconName;

class ComponentIconNameTest extends TestCase
{
    #[DataProvider('segments')]
    public function testSegment(string $directory, string $expected): void
    {
        $this->assertSame($expected, ComponentIconName::segment($directory));
    }

    public static function segments(): iterable
    {
        yield ['Alert', 'alert'];
        yield ['FormInput', 'form-input'];
        yield ['HTMLEditor', 'html-editor'];
        yield ['form_input', 'form-input'];
        yield ['Card2', 'card2'];
    }

    public function testForDirectory(): void
    {
        $this->assertSame('sdc:form:text-input:arrow', ComponentIconName::forDirectory('/app/src_component', '/app/src_component/Form/TextInput', 'arrow'));
        $this->assertSame('sdc:arrow', ComponentIconName::forDirectory('/app/src_component/', '/app/src_component', 'arrow'));
    }

    public function testForDirectoryRefusesWhatItCannotName(): void
    {
        $this->assertNull(ComponentIconName::forDirectory('/app/src_component', '/app/templates', 'arrow'));
        $this->assertNull(ComponentIconName::forDirectory('/app/src_component', '/app/src_component_other', 'arrow'));
        $this->assertNull(ComponentIconName::forDirectory('/app/src_component', '/app/src_component/Alert', 'Arrow'));
        $this->assertNull(ComponentIconName::forDirectory('/app/src_component', '/app/src_component/Ölert', 'arrow'));
    }

    public function testParse(): void
    {
        $this->assertSame([['form', 'text-input'], 'arrow'], ComponentIconName::parse('sdc:form:text-input:arrow'));
        $this->assertSame([[], 'arrow'], ComponentIconName::parse('sdc:arrow'));
        $this->assertNull(ComponentIconName::parse('mdi:home'));
        $this->assertNull(ComponentIconName::parse('arrow'));
    }
}
