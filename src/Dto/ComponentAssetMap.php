<?php

namespace Tito10047\UX\TwigComponentSdc\Dto;

final class ComponentAssetMap
{
    /**
     * @param array<string, array<int, array{path: string, type: string, priority: int, attributes: array<string, mixed>}>|string> $map
     */
    public function __construct(
        private array $map = []
    ) {
    }

    /**
     * @return array<int, array{path: string, type: string, priority: int, attributes: array<string, mixed>}>
     */
    public function getAssetsForComponent(string $componentName): array
    {
        $assets = $this->map[$componentName] ?? [];
        return is_array($assets) ? $assets : [];
    }

    /**
     * @return array<string, array<int, array{path: string, type: string, priority: int, attributes: array<string, mixed>}>|string>
     */
    public function getMap(): array
    {
        return $this->map;
    }
}
