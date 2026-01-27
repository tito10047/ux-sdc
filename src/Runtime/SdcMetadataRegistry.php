<?php

namespace Tito10047\UX\Sdc\Runtime;

class SdcMetadataRegistry
{
    private ?array $metadata = null;

    public function __construct(
        private string $cachePath
    ) {
    }

    public function getMetadata(string $componentName): array|string|null
    {
        if (null === $this->metadata) {
            if (file_exists($this->cachePath)) {
                $this->metadata = require $this->cachePath;
            } else {
                $this->metadata = [];
            }
        }

        return $this->metadata[$componentName] ?? null;
    }
}
