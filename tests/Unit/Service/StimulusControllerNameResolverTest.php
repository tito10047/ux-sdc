<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tito10047\UX\Sdc\Service\StimulusControllerNameResolver;
use Tito10047\UX\Sdc\Tests\Integration\Fixtures\Component\Stimulus\Own\OwnMethod;
use Tito10047\UX\Sdc\Tests\Integration\Fixtures\Component\Stimulus\Own\OwnPrivateProperty;
use Tito10047\UX\Sdc\Tests\Integration\Fixtures\Component\Stimulus\Own\OwnProperty;
use Tito10047\UX\Sdc\Tests\Integration\Fixtures\Component\Stimulus\Plain\Plain;

final class StimulusControllerNameResolverTest extends TestCase
{
    /**
     * The expected names are those StimulusBundle gives the `_controller.js`
     * file next to each class: its path relative to the component directory,
     * `/` as `--`, `_` as `-`, case kept.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function provideClasses(): iterable
    {
        yield 'top level' => ['App\Component\Alert', 'Alert'];
        yield 'nested' => ['App\Component\UI\Alert\Alert', 'UI--Alert--Alert'];
        yield 'case kept' => ['App\Component\UI\DatePicker\DatePicker', 'UI--DatePicker--DatePicker'];
        yield 'underscores' => ['App\Component\Ui_Kit\Date_Picker\Date_Picker', 'Ui-Kit--Date-Picker--Date-Picker'];
        yield 'leading backslash' => ['\App\Component\UI\Alert\Alert', 'UI--Alert--Alert'];
    }

    #[DataProvider('provideClasses')]
    public function testNamesTheControllerAfterTheClassRelativeToTheNamespace(string $class, string $expected): void
    {
        $this->assertSame($expected, (new StimulusControllerNameResolver('App\Component\\'))->resolve($class));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNamespaceSpellings(): iterable
    {
        yield 'trailing backslash' => ['App\Component\\'];
        yield 'without trailing backslash' => ['App\Component'];
        yield 'leading backslash' => ['\App\Component'];
    }

    #[DataProvider('provideNamespaceSpellings')]
    public function testNamespaceSpelling(string $namespace): void
    {
        $this->assertSame('UI--Alert--Alert', (new StimulusControllerNameResolver($namespace))->resolve('App\Component\UI\Alert\Alert'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideClassesOutsideTheNamespace(): iterable
    {
        yield 'another namespace' => ['App\Twig\Alert'];
        yield 'namespace as a prefix of a longer segment' => ['App\ComponentLibrary\Alert'];
        yield 'namespace further in' => ['Vendor\App\Component\Alert'];
        yield 'namespace itself' => ['App\Component'];
    }

    #[DataProvider('provideClassesOutsideTheNamespace')]
    public function testClassOutsideTheNamespaceHasNoName(string $class): void
    {
        $this->assertNull((new StimulusControllerNameResolver('App\Component'))->resolve($class));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function provideNoNamespace(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'root' => ['\\'];
    }

    #[DataProvider('provideNoNamespace')]
    public function testWithoutNamespaceNoClassHasAName(?string $namespace): void
    {
        $this->assertNull((new StimulusControllerNameResolver($namespace))->resolve('App\Component\Alert'));
    }

    /**
     * A `controller` of the component's own wins over the derived name: the
     * Stimulus trait, #[ExposeInTemplate], or a public property when public
     * properties are exposed.
     */
    public function testKnowsAComponentThatExposesItsOwnController(): void
    {
        $resolver = new StimulusControllerNameResolver('App\\Component');

        $this->assertTrue($resolver->isExposedByComponent(OwnMethod::class, false));
        $this->assertTrue($resolver->isExposedByComponent(OwnPrivateProperty::class, false));
        $this->assertTrue($resolver->isExposedByComponent(OwnProperty::class, true));
        $this->assertFalse($resolver->isExposedByComponent(OwnProperty::class, false), 'Public properties are not exposed.');
        $this->assertFalse($resolver->isExposedByComponent(Plain::class, true));
    }
}
