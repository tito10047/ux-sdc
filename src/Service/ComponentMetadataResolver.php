<?php

namespace Tito10047\UX\Sdc\Service;

use ReflectionClass;
use Tito10047\UX\Sdc\Attribute\Asset;
use Tito10047\UX\Sdc\Attribute\AsSdcComponent;

class ComponentMetadataResolver
{
    private array $resolvedRoots = [];
    private array $classCache = [];

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

        if ($this->autoDiscoveryEnabled) {
            $dir = dirname($reflectionClass->getFileName());
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

    private function collectExplicitAssets(ReflectionClass $reflectionClass): array
    {
        $attributes = $reflectionClass->getAttributes();
        if (empty($attributes)) {
            return [];
        }

        $assets = [];

        foreach ($attributes as $attribute) {
            $attributeName = $attribute->getName();

            if ($attributeName === Asset::class) {
                /** @var Asset $asset */
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
        }

        foreach ($attributes as $attribute) {
            $attributeName = $attribute->getName();

            if ($attributeName === AsSdcComponent::class) {
                /** @var AsSdcComponent $sdcComponent */
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
