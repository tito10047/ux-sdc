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

use Psr\Log\LoggerInterface;
use ReflectionClass;
use Tito10047\UX\Sdc\Attribute\Asset;
use Tito10047\UX\Sdc\Attribute\AsSdcComponent;

/**
 * Resolves the assets and template co-located with a component class.
 *
 * A co-located file is addressed by its path relative to the Twig path it sits
 * in: that is its Twig name and, for `ux_sdc.ux_components_dir` (registered as
 * both a Twig path and an AssetMapper path), its AssetMapper logical path too.
 * A file outside every Twig path has no such name, so it is left out — and,
 * in debug, reported to the logger. Single-directory components are supported
 * under `ux_components_dir`; see the README.
 *
 * @phpstan-import-type SdcAsset from AssetRegistry
 */
class ComponentMetadataResolver
{
    /**
     * @var list<string> real paths, with a trailing directory separator
     */
    private array $resolvedRoots = [];

    /**
     * Per-class resolution cache, deliberately kept for the lifetime of the
     * process and not reset between requests (this service is not tagged
     * `kernel.reset`).
     *
     * Every entry is derived from the component class alone — its attributes and
     * the files sitting next to it — never from the request, so a worker serving
     * many requests can safely reuse it. The per-request state lives in
     * AssetRegistry, which is reset. Clearing this cache as well would only make
     * every request re-run reflection and filesystem lookups, and log the same
     * warnings again.
     *
     * At runtime the resolver is used when `ux_sdc.metadata_cache` resolves to
     * false, debug or not. Under PHP-FPM each request gets a fresh instance, so
     * an asset file added next to a component shows up on the next request; a
     * long-running worker picks it up once the worker restarts.
     *
     * @var array<string, ComponentMetadata>
     */
    private array $classCache = [];

    /**
     * @param array<string> $twigRoots
     * @param bool          $debug     a file left out is reported only in debug: it is a
     *                                 mistake in the application's code, the same in every
     *                                 environment, and a production log would get it from
     *                                 every process for as long as the code stays as it is
     */
    public function __construct(
        private array $twigRoots,
        private bool $autoDiscoveryEnabled,
        private ?LoggerInterface $logger = null,
        private bool $debug = false,
    ) {
        foreach ($this->twigRoots as $root) {
            $realRoot = realpath($root);
            if ($realRoot) {
                $this->resolvedRoots[] = $realRoot . DIRECTORY_SEPARATOR;
            }
        }
    }

    public function resolve(string $class): ComponentMetadata
    {
        if (isset($this->classCache[$class])) {
            return $this->classCache[$class];
        }

        if (!class_exists($class)) {
            return new ComponentMetadata();
        }

        $reflectionClass = new ReflectionClass($class);
        $assets = $this->collectExplicitAssets($reflectionClass);
        $template = null;
        $fileName = $reflectionClass->getFileName();

        // Internal classes have no file, hence no siblings to discover.
        if ($this->autoDiscoveryEnabled && false !== $fileName) {
            $basePath = dirname($fileName) . DIRECTORY_SEPARATOR . $reflectionClass->getShortName();

            foreach (['css', 'js'] as $ext) {
                $path = $this->findCoLocatedFile($class, $basePath . '.' . $ext);
                if (null !== $path) {
                    $assets[] = [
                        'path' => $path,
                        'type' => $ext,
                        'priority' => 0,
                        'attributes' => [],
                    ];
                }
            }

            $template = $this->findCoLocatedFile($class, $basePath . '.html.twig');
        }

        return $this->classCache[$class] = new ComponentMetadata($assets, $template);
    }

    /**
     * @param ReflectionClass<object> $reflectionClass
     *
     * @return list<SdcAsset>
     */
    private function collectExplicitAssets(ReflectionClass $reflectionClass): array
    {
        $assets = [];

        foreach ($reflectionClass->getAttributes(Asset::class) as $attribute) {
            $asset = $attribute->newInstance();
            if ($asset->path) {
                $assets[] = [
                    'path' => $asset->path,
                    'type' => $asset->type ?? '',
                    'priority' => $asset->priority,
                    'attributes' => $asset->attributes,
                ];
            }
        }

        foreach ($reflectionClass->getAttributes(AsSdcComponent::class) as $attribute) {
            $sdcComponent = $attribute->newInstance();

            if ($sdcComponent->css) {
                $assets[] = [
                    'path' => $sdcComponent->css,
                    'type' => 'css',
                    'priority' => 0,
                    'attributes' => [],
                ];
            }

            if ($sdcComponent->js) {
                $assets[] = [
                    'path' => $sdcComponent->js,
                    'type' => 'js',
                    'priority' => 0,
                    'attributes' => [],
                ];
            }
        }

        return $assets;
    }

    /**
     * @return string|null the path of the file relative to its Twig path; null
     *                     when there is no such file or it is outside every Twig path
     */
    private function findCoLocatedFile(string $class, string $file): ?string
    {
        if (!file_exists($file)) {
            return null;
        }

        $realFile = realpath($file) ?: $file;
        $relativePath = $this->findShortestRelativePath($realFile);

        if (null === $relativePath && $this->debug) {
            $this->reportOutsideTwigPaths($class, $realFile);
        }

        return $relativePath;
    }

    private function reportOutsideTwigPaths(string $class, string $file): void
    {
        $message = str_ends_with($file, '.twig')
            ? 'Component "{component}": the template "{file}" next to the class is outside every Twig path ({roots}), so it is not used. Move the component into the directory configured as "ux_sdc.ux_components_dir", or name its template explicitly with the "template" argument of #[AsTwigComponent] or #[AsSdcComponent].'
            : 'Component "{component}": "{file}" next to the class is outside every Twig path ({roots}), so it is not loaded with the component. Move the component into the directory configured as "ux_sdc.ux_components_dir", or reference the file by its AssetMapper logical path with #[Asset].';

        $roots = array_map(static fn (string $root): string => rtrim($root, DIRECTORY_SEPARATOR), $this->resolvedRoots);

        $this->logger?->warning($message, [
            'component' => $class,
            'file' => $file,
            'roots' => [] === $roots ? 'none configured' : implode(', ', $roots),
        ]);
    }

    private function findShortestRelativePath(string $filePath): ?string
    {
        $shortestPath = null;
        foreach ($this->resolvedRoots as $root) {
            if (str_starts_with($filePath, $root)) {
                $relativePath = substr($filePath, strlen($root));
                if ($shortestPath === null || strlen($relativePath) < strlen($shortestPath)) {
                    $shortestPath = $relativePath;
                }
            }
        }

        return $shortestPath;
    }
}
