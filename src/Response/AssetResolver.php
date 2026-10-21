<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Response;

use Psr\Log\LoggerInterface;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Tito10047\UX\Sdc\Service\AssetPath;
use Tito10047\UX\Sdc\Service\AssetRegistry;
use Tito10047\UX\Sdc\Service\AssetType;

/**
 * Turns the assets collected for a response into the ones it links to, with
 * their public paths.
 *
 * @phpstan-import-type SdcAsset from AssetRegistry
 *
 * @internal
 */
final class AssetResolver
{
    /**
     * @param bool $debug an asset path the asset map does not know is reported only in
     *                    debug: it is a mistake in an attribute, and in production it
     *                    would be logged on every request that renders the component
     */
    public function __construct(
        private readonly AssetMapperInterface $assetMapper,
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $debug = false,
    ) {
    }

    /**
     * The assets with their public paths and their type narrowed to `css` or
     * `js`, in the order given. Assets of any other type are left out.
     *
     * @param list<SdcAsset> $assets as AssetRegistry::getSortedAssets() returns them
     *
     * @return list<ResolvedAsset>
     */
    public function resolve(array $assets): array
    {
        $resolved = [];

        foreach ($assets as $asset) {
            $path = $this->assetMapper->getAsset($asset['path'])->publicPath ?? $this->passThrough($asset['path']);

            $type = match (true) {
                AssetType::CSS === $asset['type'], '' === $asset['type'] && str_ends_with($path, '.css') => AssetType::CSS,
                AssetType::JS === $asset['type'], '' === $asset['type'] && str_ends_with($path, '.js') => AssetType::JS,
                default => null,
            };

            if (null !== $type) {
                $resolved[] = new ResolvedAsset($path, $type, $asset['attributes']);
            }
        }

        return $resolved;
    }

    /**
     * A path the asset map does not know is linked as it is. That is what a
     * URL is for; anything else was meant as a logical path — a typo in
     * #[Asset], or a file AssetMapper does not serve — and will not load.
     */
    private function passThrough(string $path): string
    {
        if ($this->debug && !AssetPath::isUrl($path)) {
            $this->logger?->warning(
                'The component asset "{path}" is not in the asset map, so it is linked as it is and will most likely not load. Reference it by its AssetMapper logical path (see "bin/console debug:asset-map" and "bin/console debug:sdc"), or by a URL.',
                ['path' => $path]
            );
        }

        return $path;
    }
}
