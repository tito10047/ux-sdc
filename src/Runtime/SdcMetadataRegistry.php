<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Runtime;

use Psr\Log\LoggerInterface;
use Tito10047\UX\Sdc\Service\ComponentMetadata;

/**
 * Reads the component metadata AssetComponentCompilerPass resolved when the
 * container was built.
 *
 * `SdcMetadataCache` is the shape of `sdc_metadata.php`: component class to its
 * assets, its co-located template and the files next to it that could not be
 * used. Classes with none of these are left out.
 *
 * @phpstan-import-type SdcComponentAsset from ComponentMetadata
 * @phpstan-import-type SdcProblem from ComponentMetadata
 * @phpstan-type SdcMetadataCache array<string, array{assets: list<SdcComponentAsset>, template: string|null, problems: list<SdcProblem>}>
 */
class SdcMetadataRegistry
{
    /**
     * @var SdcMetadataCache|null
     */
    private ?array $metadata = null;

    /**
     * @param bool $cacheEnabled whether `ux_sdc.metadata_cache` resolved to true, i.e. whether
     *                           the container build wrote the file. Without it the registry is
     *                           empty and never touches the filesystem.
     */
    public function __construct(
        private string $cachePath,
        private bool $cacheEnabled = true,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return list<SdcComponentAsset>
     */
    public function getAssets(string $class): array
    {
        return $this->load()[$class]['assets'] ?? [];
    }

    /**
     * @return string|null the Twig name of the template next to the component class
     */
    public function getTemplate(string $class): ?string
    {
        return $this->load()[$class]['template'] ?? null;
    }

    /**
     * Everything the build resolved for the component class, the files it had
     * to leave out included.
     */
    public function getComponentMetadata(string $class): ComponentMetadata
    {
        $metadata = $this->load()[$class] ?? null;

        if (null === $metadata) {
            return new ComponentMetadata();
        }

        return new ComponentMetadata($metadata['assets'], $metadata['template'], $metadata['problems']);
    }

    /**
     * @return SdcMetadataCache
     */
    private function load(): array
    {
        if (null !== $this->metadata) {
            return $this->metadata;
        }

        if (!$this->cacheEnabled) {
            return $this->metadata = [];
        }

        if (!file_exists($this->cachePath)) {
            // Logged in every environment, debug or not: the container build
            // always writes this file, so its absence is a deployment problem
            // that production is the first to see. Not an exception: the pages
            // still render, only without the component CSS, JS and co-located
            // templates. Logged once per instance, i.e. once per request under
            // PHP-FPM and once per worker in a long-running runtime.
            $this->logger?->error(
                'The component metadata cache "{path}" does not exist, so no component gets its CSS, JS or co-located template. The file is written when the container is compiled: run "bin/console cache:warmup" where the application runs (or ship the file with the rest of the cache), and make sure the runtime cache directory is the one the container was built with (e.g. the same APP_CACHE_DIR).',
                ['path' => $this->cachePath]
            );

            return $this->metadata = [];
        }

        return $this->metadata = require $this->cachePath;
    }
}
