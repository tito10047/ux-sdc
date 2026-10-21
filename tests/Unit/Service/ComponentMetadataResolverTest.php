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

use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Tito10047\UX\Sdc\Service\ComponentMetadataResolver;
use Tito10047\UX\Sdc\Tests\TestLogger;
use Tito10047\UX\Sdc\Tests\Unit\Service\Fixtures\Alert\Alert;

final class ComponentMetadataResolverTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/Fixtures';

    /**
     * A directory that exists but does not contain the fixture.
     */
    private const UNRELATED_ROOT = __DIR__ . '/../../Integration/Fixtures/templates';

    private const EXPLICIT_ASSET = ['path' => 'vendor/explicit.css', 'type' => '', 'priority' => 3, 'attributes' => []];

    public function testResolvesFilesNextToTheClassRelativeToTheirTwigPath(): void
    {
        $logger = new TestLogger();
        $metadata = (new ComponentMetadataResolver([self::FIXTURES], true, $logger, true))->resolve(Alert::class);

        $this->assertSame([
            self::EXPLICIT_ASSET,
            ['path' => 'Alert/Alert.css', 'type' => 'css', 'priority' => 0, 'attributes' => []],
            ['path' => 'Alert/Alert.js', 'type' => 'js', 'priority' => 0, 'attributes' => []],
        ], $metadata->assets);
        $this->assertSame('Alert/Alert.html.twig', $metadata->template);
        $this->assertSame([], $logger->records);
    }

    /**
     * A file outside every Twig path has no name to be loaded by: it is left
     * out instead of being guessed (it used to become "Alert.css", a 404).
     */
    public function testLeavesOutFilesOutsideEveryTwigPathAndWarnsInDebug(): void
    {
        $logger = new TestLogger();
        $metadata = (new ComponentMetadataResolver([self::UNRELATED_ROOT], true, $logger, true))->resolve(Alert::class);

        $this->assertSame([self::EXPLICIT_ASSET], $metadata->assets, 'Explicit assets are not affected.');
        $this->assertNull($metadata->template);

        $warnings = $logger->recordsOf(LogLevel::WARNING);
        $this->assertCount(3, $warnings);
        $this->assertSame($logger->records, $warnings);

        $files = array_map(static fn (array $record): mixed => $record['context']['file'], $warnings);
        $this->assertSame([
            realpath(self::FIXTURES . '/Alert/Alert.css'),
            realpath(self::FIXTURES . '/Alert/Alert.js'),
            realpath(self::FIXTURES . '/Alert/Alert.html.twig'),
        ], $files);

        foreach ($warnings as $warning) {
            $this->assertSame(Alert::class, $warning['context']['component']);
            $this->assertSame(realpath(self::UNRELATED_ROOT), $warning['context']['roots']);
            $this->assertStringContainsString('"{component}"', $warning['message']);
            $this->assertStringContainsString('ux_sdc.ux_components_dir', $warning['message']);
        }

        $this->assertStringContainsString('#[Asset]', $warnings[0]['message']);
        $this->assertStringContainsString('"template" argument', $warnings[2]['message']);
    }

    public function testWarnsOncePerClass(): void
    {
        $logger = new TestLogger();
        $resolver = new ComponentMetadataResolver([self::UNRELATED_ROOT], true, $logger, true);

        $resolver->resolve(Alert::class);
        $resolver->resolve(Alert::class);

        $this->assertCount(3, $logger->records);
    }

    public function testDoesNotWarnOutsideDebug(): void
    {
        $logger = new TestLogger();
        $metadata = (new ComponentMetadataResolver([self::UNRELATED_ROOT], true, $logger, false))->resolve(Alert::class);

        $this->assertSame([self::EXPLICIT_ASSET], $metadata->assets);
        $this->assertNull($metadata->template);
        $this->assertSame([], $logger->records);
    }

    public function testNamesTheMissingRootsWhenThereAreNone(): void
    {
        $logger = new TestLogger();
        (new ComponentMetadataResolver([], true, $logger, true))->resolve(Alert::class);

        $this->assertSame('none configured', $logger->records[0]['context']['roots']);
    }

    public function testWithoutAutoDiscoveryOnlyExplicitAssetsAreResolved(): void
    {
        $logger = new TestLogger();
        $metadata = (new ComponentMetadataResolver([self::UNRELATED_ROOT], false, $logger, true))->resolve(Alert::class);

        $this->assertSame([self::EXPLICIT_ASSET], $metadata->assets);
        $this->assertNull($metadata->template);
        $this->assertSame([], $logger->records);
    }

    public function testUnknownClassHasNoMetadata(): void
    {
        $metadata = (new ComponentMetadataResolver([self::FIXTURES], true))->resolve('App\\DoesNotExist');

        $this->assertTrue($metadata->isEmpty());
    }
}
