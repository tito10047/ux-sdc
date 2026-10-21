<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Tito10047\UX\Sdc\Runtime\SdcMetadataRegistry;
use Tito10047\UX\Sdc\Tests\TestLogger;

class SdcMetadataRegistryTest extends TestCase
{
    private string $cachePath;

    protected function setUp(): void
    {
        $this->cachePath = sys_get_temp_dir() . '/sdc_metadata_test.php';
        if (file_exists($this->cachePath)) {
            unlink($this->cachePath);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->cachePath)) {
            unlink($this->cachePath);
        }
    }

    public function testReadsAssetsAndTemplateFromTheCacheFile(): void
    {
        $asset = ['path' => 'Alert/Alert.css', 'type' => 'css', 'priority' => 0, 'attributes' => []];
        $this->writeCache([
            'App\Alert' => ['assets' => [$asset], 'template' => 'Alert/Alert.html.twig', 'problems' => []],
            'App\Icon' => ['assets' => [], 'template' => 'Icon/Icon.html.twig', 'problems' => []],
        ]);

        $registry = new SdcMetadataRegistry($this->cachePath);

        $this->assertSame([$asset], $registry->getAssets('App\Alert'));
        $this->assertSame('Alert/Alert.html.twig', $registry->getTemplate('App\Alert'));
        $this->assertSame([], $registry->getAssets('App\Icon'));
        $this->assertSame('Icon/Icon.html.twig', $registry->getTemplate('App\Icon'));
    }

    public function testGivesTheWholeMetadataOfAClass(): void
    {
        $asset = ['path' => 'Alert/Alert.css', 'type' => 'css', 'priority' => 0, 'attributes' => [], 'source' => 'discovered'];
        $problem = ['message' => '"{file}" is outside', 'context' => ['file' => '/app/Alert.js']];
        $this->writeCache([
            'App\Alert' => ['assets' => [$asset], 'template' => 'Alert/Alert.html.twig', 'problems' => [$problem]],
        ]);

        $registry = new SdcMetadataRegistry($this->cachePath);

        $metadata = $registry->getComponentMetadata('App\Alert');
        $this->assertSame([$asset], $metadata->assets);
        $this->assertSame('Alert/Alert.html.twig', $metadata->template);
        $this->assertSame([$problem], $metadata->problems);
        $this->assertSame(['"/app/Alert.js" is outside'], $metadata->getProblemMessages());

        $this->assertTrue($registry->getComponentMetadata('App\Unknown')->isEmpty());
    }

    public function testGivesTheMetadataOfAnAnonymousComponentByTemplate(): void
    {
        $asset = ['path' => 'Notice/Notice.css', 'type' => 'css', 'priority' => 0, 'attributes' => [], 'source' => 'discovered'];
        $problem = ['message' => '"{file}" is outside', 'context' => ['file' => '/app/Notice.js']];
        $this->writeCache([], [
            'Notice/Notice.html.twig' => ['assets' => [$asset], 'problems' => [$problem]],
        ]);

        $registry = new SdcMetadataRegistry($this->cachePath);

        $metadata = $registry->getAnonymousComponentMetadata('Notice/Notice.html.twig');
        $this->assertSame([$asset], $metadata->assets);
        $this->assertNull($metadata->template);
        $this->assertSame([$problem], $metadata->problems);

        $this->assertTrue($registry->getAnonymousComponentMetadata('components/Unknown.html.twig')->isEmpty());
        $this->assertTrue($registry->getComponentMetadata('Notice/Notice.html.twig')->isEmpty(), 'Classes and templates are looked up apart.');
        $this->assertTrue($registry->getAnonymousComponentMetadata('App\Unknown')->isEmpty());
    }

    public function testUnknownClassHasNoAssetsAndNoTemplate(): void
    {
        $this->writeCache([]);

        $registry = new SdcMetadataRegistry($this->cachePath);

        $this->assertSame([], $registry->getAssets('App\Unknown'));
        $this->assertNull($registry->getTemplate('App\Unknown'));
    }

    /**
     * A deployment error: logged in every environment, as an error, and once.
     */
    public function testMissingCacheFileIsLoggedAsAnError(): void
    {
        $logger = new TestLogger();
        $registry = new SdcMetadataRegistry($this->cachePath, true, $logger);

        $this->assertSame([], $registry->getAssets('App\Alert'));
        $this->assertNull($registry->getTemplate('App\Alert'));
        $this->assertTrue($registry->getAnonymousComponentMetadata('Notice/Notice.html.twig')->isEmpty());

        $this->assertCount(1, $logger->records);
        $this->assertSame(LogLevel::ERROR, $logger->records[0]['level']);
        $this->assertSame($this->cachePath, $logger->records[0]['context']['path']);
        $this->assertStringContainsString('{path}', $logger->records[0]['message']);
        $this->assertStringContainsString('cache:warmup', $logger->records[0]['message']);
    }

    public function testMissingCacheFileWithoutLoggerIsEmpty(): void
    {
        $registry = new SdcMetadataRegistry($this->cachePath);

        $this->assertSame([], $registry->getAssets('App\Alert'));
        $this->assertNull($registry->getTemplate('App\Alert'));
    }

    /**
     * With the cache off nothing writes the file: its absence is expected, and a
     * leftover file is not read.
     */
    public function testDisabledCacheIsEmptyAndSilent(): void
    {
        $logger = new TestLogger();
        $registry = new SdcMetadataRegistry($this->cachePath, false, $logger);

        $this->assertSame([], $registry->getAssets('App\Alert'));
        $this->assertSame([], $logger->records);

        $this->writeCache(['App\Alert' => ['assets' => [], 'template' => 'Alert/Alert.html.twig', 'problems' => []]]);
        $this->assertNull((new SdcMetadataRegistry($this->cachePath, false, $logger))->getTemplate('App\Alert'));
    }

    /**
     * @param array<string, mixed> $components
     * @param array<string, mixed> $anonymous
     */
    private function writeCache(array $components, array $anonymous = []): void
    {
        file_put_contents($this->cachePath, '<?php return ' . var_export(['components' => $components, 'anonymous' => $anonymous], true) . ';');
    }
}
