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

use ReflectionClass;
use Tito10047\UX\Sdc\Attribute\Asset;
use Tito10047\UX\Sdc\Attribute\AsSdcComponent;

/**
 * Resolves the assets and template co-located with a component class.
 *
 * `SdcMetadata` is the flat map dumped to `sdc_metadata.php`: the component class
 * (or name) maps to its assets, `<class>_template` maps to its template path.
 *
 * @phpstan-import-type SdcAsset from AssetRegistry
 * @phpstan-type SdcMetadata array<string, list<SdcAsset>|string>
 */
class ComponentMetadataResolver
{
    /**
     * @var list<string>
     */
    private array $resolvedRoots = [];

    /**
     * @var array<string, array{assets: list<SdcAsset>, template: ?string}>
     */
    private array $classCache = [];

    /**
     * @param array<string> $twigRoots
     */
    public function __construct(
        private array $twigRoots,
        private bool $autoDiscoveryEnabled
    ) {
        foreach ($this->twigRoots as $root) {
            $realRoot = realpath($root);
            if ($realRoot) {
                $this->resolvedRoots[] = $realRoot . DIRECTORY_SEPARATOR;
            }
        }
    }

    /**
     * @param SdcMetadata $allMetadata receives the `<componentName>_template` entry, if any
     *
     * @param-out SdcMetadata $allMetadata
     *
     * @return list<SdcAsset>
     */
    public function resolveMetadata(string $class, string $componentName, array &$allMetadata = []): array
    {
        if (isset($this->classCache[$class])) {
            $cached = $this->classCache[$class];
            if (isset($cached['template'])) {
                $allMetadata[$componentName . '_template'] = $cached['template'];
            }
            return $cached['assets'];
        }

        if (!class_exists($class)) {
            return [];
        }

        $reflectionClass = new ReflectionClass($class);
        $assets = $this->collectExplicitAssets($reflectionClass);
        $template = null;
        $fileName = $reflectionClass->getFileName();

        // Internal classes have no file, hence no siblings to discover.
        if ($this->autoDiscoveryEnabled && false !== $fileName) {
            $dir = dirname($fileName);
            $baseName = $reflectionClass->getShortName();
            $basePath = $dir . DIRECTORY_SEPARATOR . $baseName;

            foreach (['css', 'js'] as $ext) {
                $file = $basePath . '.' . $ext;
                if (file_exists($file)) {
                    $realFile = realpath($file);
                    $shortestPath = $this->findShortestRelativePath($realFile ?: $file);
                    $assets[] = [
                        'path' => $shortestPath ?: ($baseName . '.' . $ext),
                        'type' => $ext,
                        'priority' => 0,
                        'attributes' => [],
                    ];
                }
            }

            $twigFile = $basePath . '.html.twig';
            if (file_exists($twigFile)) {
                $realTwigFile = realpath($twigFile);
                $shortestPath = $this->findShortestRelativePath($realTwigFile ?: $twigFile);
                if ($shortestPath) {
                    $template = $shortestPath;
                    $allMetadata[$componentName . '_template'] = $shortestPath;
                }
            }
        }

        $this->classCache[$class] = [
            'assets' => $assets,
            'template' => $template,
        ];

        return $assets;
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
