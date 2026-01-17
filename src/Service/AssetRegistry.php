<?php

namespace Tito10047\UX\TwigComponentSdc\Service;

final class AssetRegistry
{
    private array $assets = [];

    public function addAsset(string $path, string $type, int $priority = 0, array $attributes = []): void
    {
        $key = md5($path . $type . serialize($attributes));

        if (!isset($this->assets[$key])) {
            $this->assets[$key] = [
                'path' => $path,
                'type' => $type,
                'priority' => $priority,
                'attributes' => $attributes,
            ];
        } else {
            // Ak už existuje, môžeme aktualizovať prioritu na vyššiu
            if ($priority > $this->assets[$key]['priority']) {
                $this->assets[$key]['priority'] = $priority;
            }
        }
    }

    /**
     * @return array<int, array{path: string, type: string, priority: int, attributes: array}>
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
}
