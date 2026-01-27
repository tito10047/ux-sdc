<?php

namespace Tito10047\UX\Sdc\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Tito10047\UX\Sdc\Runtime\SdcMetadataRegistry;

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

    public function testGetMetadataLoadsFromFile(): void
    {
        $data = [
            'Alert' => [
                'has_css' => true,
                'has_js' => false,
                'template' => 'Alert.html.twig',
            ]
        ];

        file_put_contents($this->cachePath, '<?php return ' . var_export($data, true) . ';');

        $registry = new SdcMetadataRegistry($this->cachePath);

        $this->assertEquals($data['Alert'], $registry->getMetadata('Alert'));
        $this->assertNull($registry->getMetadata('NonExistent'));
    }

    public function testGetMetadataReturnsEmptyIfFileDoesNotExist(): void
    {
        $registry = new SdcMetadataRegistry($this->cachePath);
        $this->assertNull($registry->getMetadata('Alert'));
    }
}
