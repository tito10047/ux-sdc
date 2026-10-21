<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Service;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Shape of a single asset, shared by the metadata resolver, the compiled metadata
 * cache and this registry. `type` is one of the type constants of #[Asset]; an
 * asset added here with an empty type gets the one its path tells when the
 * response is built, and one of no known type is not loaded.
 *
 * @phpstan-type SdcAsset array{path: string, type: string, priority: int, attributes: array<string, mixed>}
 *
 * @internal Components declare their assets with #[Asset] and #[AsSdcComponent].
 */
final class AssetRegistry implements ResetInterface
{
    /**
     * @var array<string, SdcAsset>
     */
    private array $assets = [];

    /**
     * @param array<string, mixed> $attributes
     */
    public function addAsset(string $path, string $type, int $priority = 0, array $attributes = []): void
    {
        $key = $path . '|' . $type;
        if (!empty($attributes)) {
            $key .= '|' . serialize($attributes);
        }

        if (!isset($this->assets[$key])) {
            $this->assets[$key] = [
                'path' => $path,
                'type' => $type,
                'priority' => $priority,
                'attributes' => $attributes,
            ];
        } elseif ($priority > $this->assets[$key]['priority']) {
            // The same asset requested again: it is loaded once, with the highest priority asked for.
            $this->assets[$key]['priority'] = $priority;
        }
    }

    /**
     * @return list<SdcAsset>
     */
    public function getSortedAssets(): array
    {
        $sorted = array_values($this->assets);

        usort($sorted, function ($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });

        return $sorted;
    }

    public function clear(): void
    {
        $this->assets = [];
    }

    /**
     * Called by the `services_resetter` between requests.
     *
     * The registry collects the assets of one response. In a long-running worker
     * (FrankenPHP worker mode, RoadRunner, Swoole) the container outlives the request,
     * so without this every page would also receive the assets of every component
     * rendered on the pages served before it.
     */
    public function reset(): void
    {
        $this->clear();
    }
}
