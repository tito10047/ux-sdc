<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Integration;

use InvalidArgumentException;
use Throwable;
use Tito10047\UX\Sdc\Tests\Integration\Fixtures\InvalidAsset\Untyped\Untyped;

/**
 * An #[Asset] whose type is neither declared nor told by its path fails
 * loudly, naming the component, instead of being loaded as a script — at
 * build time with the metadata cache, on render without it.
 */
final class InvalidAssetFunctionalTest extends FunctionalTestCase
{
    private const MESSAGE = 'An #[Asset] of the component "' . Untyped::class . '" is invalid: The type of the asset "https://cdn.example.com/lib" cannot be told from its path.';

    public function testFailsTheContainerBuildWithTheMetadataCache(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(self::MESSAGE);

        self::bootKernel($this->options(true));
    }

    public function testFailsTheRenderWithoutTheMetadataCache(): void
    {
        $client = self::createClient($this->options(false));
        $client->catchExceptions(false);

        try {
            $client->request('GET', '/page/untyped_asset.html.twig');
            $this->fail('The page must not render.');
        } catch (Throwable $e) {
            // Twig wraps the exception of the render listener.
            while (!$e instanceof InvalidArgumentException && null !== $e->getPrevious()) {
                $e = $e->getPrevious();
            }

            $this->assertInstanceOf(InvalidArgumentException::class, $e);
            $this->assertStringStartsWith(self::MESSAGE, $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function options(bool $metadataCache): array
    {
        return [
            'configs' => [
                'metadata_cache' => $metadataCache,
                'component_namespace' => 'Tito10047\\UX\\Sdc\\Tests\\Integration\\Fixtures\\InvalidAsset',
                'ux_components_dir' => '%kernel.project_dir%/tests/Integration/Fixtures/InvalidAsset',
            ],
        ];
    }
}
