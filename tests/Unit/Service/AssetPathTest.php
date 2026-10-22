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
use Tito10047\UX\Sdc\Service\AssetPath;

final class AssetPathTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function providePaths(): iterable
    {
        yield 'absolute URL' => ['https://cdn.example.com/lib.css', true];
        yield 'scheme in another case' => ['HTTP://cdn.example.com/lib.css', true];
        yield 'protocol-relative URL' => ['//cdn.example.com/lib.css', true];
        yield 'root-relative path' => ['/build/app.css', true];
        yield 'data URI' => ['data:text/css,body{}', true];
        yield 'logical path' => ['Alert/Alert.css', false];
        yield 'namespaced logical path' => ['lib/ui/Badge/Badge.js', false];
        yield 'file name' => ['app.css', false];
        yield 'relative path' => ['./app.css', false];
    }

    #[DataProvider('providePaths')]
    public function testTellsAUrlFromALogicalPath(string $path, bool $isUrl): void
    {
        $this->assertSame($isUrl, AssetPath::isUrl($path));
    }
}
