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

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tito10047\UX\Sdc\Attribute\Asset;

final class AssetTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideInferredTypes(): iterable
    {
        yield 'stylesheet' => ['styles/reset.css', Asset::CSS];
        yield 'script' => ['https://cdn.example.com/chart.umd.js', Asset::JS];
        yield 'font' => ['fonts/inter.woff2', Asset::FONT];
        yield 'image' => ['images/hero.avif', Asset::IMAGE];
    }

    #[DataProvider('provideInferredTypes')]
    public function testWithoutATypeTheExtensionTellsIt(string $path, string $type): void
    {
        $this->assertSame($type, (new Asset($path))->type);
    }

    public function testADeclaredTypeWinsOverTheExtension(): void
    {
        $this->assertSame(Asset::CSS, (new Asset('https://fonts.googleapis.com/css2?family=Inter', Asset::CSS))->type);
        $this->assertSame(Asset::FONT, (new Asset('https://cdn.example.com/font', type: 'font'))->type);
        $this->assertSame(Asset::JS, (new Asset('legacy.txt', type: 'js'))->type);
    }

    public function testKeepsTheOtherArguments(): void
    {
        $asset = new Asset('styles/print.css', priority: 5, attributes: ['media' => 'print']);

        $this->assertSame('styles/print.css', $asset->path);
        $this->assertSame(5, $asset->priority);
        $this->assertSame(['media' => 'print'], $asset->attributes);
    }

    /**
     * Before 1.0 such a path was loaded as a script: `<script src="…woff2">`.
     *
     * @return iterable<string, array{string}>
     */
    public static function providePathsTellingNoType(): iterable
    {
        yield 'URL without an extension' => ['https://cdn.example.com/lib'];
        yield 'stylesheet behind a query' => ['https://fonts.googleapis.com/css2?family=Inter'];
        yield 'other extension' => ['videos/intro.mp4'];
    }

    #[DataProvider('providePathsTellingNoType')]
    public function testAPathTellingNoTypeNeedsOne(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('The type of the asset "%s" cannot be told from its path. Declare it: #[Asset(type: …)] with one of "css", "js", "font", "image".', $path));

        new Asset($path);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnknownTypes(): iterable
    {
        yield 'typo' => ['fnt'];
        yield 'upper case' => ['CSS'];
        yield 'MIME type' => ['font/woff2'];
        yield 'empty' => [''];
    }

    #[DataProvider('provideUnknownTypes')]
    public function testRejectsAnUnknownType(string $type): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('The type "%s" of the asset "fonts/inter.woff2" is not one of "css", "js", "font", "image".', $type));

        new Asset('fonts/inter.woff2', $type);
    }

    public function testRejectsAnEmptyPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The path of an #[Asset] is empty.');

        new Asset(' ', Asset::CSS);
    }

    /**
     * A bare #[Asset] — shown on Live Components in examples before 1.0 —
     * declares nothing; it stays harmless.
     */
    public function testWithoutAPathThereIsNoType(): void
    {
        $asset = new Asset();

        $this->assertNull($asset->path);
        $this->assertNull($asset->type);
    }
}
