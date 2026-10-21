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

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Tito10047\UX\Sdc\Attribute\Asset;
use Tito10047\UX\Sdc\Attribute\AsSdcComponent;

/**
 * Resolves the assets and template co-located with a component class.
 *
 * A co-located file is addressed by the name it has where it is served from:
 *
 *  - the template by its Twig name, relative to the Twig path it sits in and
 *    prefixed with that path's namespace (`@ui/Alert/Alert.html.twig`); a path
 *    of the main namespace wins over a namespaced one, so a file under
 *    `ux_sdc.ux_components_dir` (registered both ways) gets the plain name;
 *  - a stylesheet or script by its AssetMapper logical path, relative to the
 *    AssetMapper path it sits in and prefixed with that path's namespace.
 *
 * The two are independent: a Twig path is not an AssetMapper path, nor the
 * other way round, and a Twig namespace plays no part in a logical path.
 * `ux_components_dir` is registered as both, with no namespace, so its files
 * get the same relative name in each. A file without such a name is left out
 * — and reported.
 *
 * A component whose registration names its template (the `template` argument
 * of #[AsTwigComponent], #[AsSdcComponent] or #[AsLiveComponent], or of the
 * `twig.component` tag) keeps it: the file next to the class is not looked up.
 *
 * An anonymous component — a template without a class — is resolved from its
 * template instead: the stylesheet and script named after the template, next
 * to it (`Alert/Alert.html.twig` → `Alert/Alert.css`, `Alert/Alert.js`).
 *
 * A file left out is a problem of the returned metadata, which `debug:sdc`
 * shows; in debug, it is also logged when the class is resolved.
 *
 * @phpstan-import-type SdcComponentAsset from ComponentMetadata
 * @phpstan-import-type SdcProblem from ComponentMetadata
 *
 * @internal
 */
final class ComponentMetadataResolver
{
    /**
     * @var list<array{string, string}> real path with a trailing directory separator, and namespace ('' for the main one)
     */
    private array $twigRoots;

    /**
     * @var list<array{string, string}> real path with a trailing directory separator, and namespace ('' for none)
     */
    private array $assetRoots;

    /**
     * @var array<string, true>
     */
    private array $classesWithExplicitTemplate;

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
     * Per anonymous component template, kept like the per-class cache: an
     * entry is derived from the template and the files next to it alone.
     *
     * @var array<string, ComponentMetadata>
     */
    private array $templateCache = [];

    /**
     * Both root sets take the shape of the configuration they come from: a list
     * of directories, or a map of directory => namespace. A directory that does
     * not exist is ignored.
     *
     * @param array<int|string, string|null> $twigRoots                   Twig paths (`twig.paths`); a null or empty namespace is the main one
     * @param array<int|string, string|null> $assetRoots                  AssetMapper paths (`framework.asset_mapper.paths`); a null or empty namespace is none
     * @param bool                           $debug                       a file left out is logged only in debug: it is a
     *                                                                    mistake in the application's code, the same in every
     *                                                                    environment, and a production log would get it from
     *                                                                    every process for as long as the code stays as it is
     * @param list<string>                   $classesWithExplicitTemplate component classes registered with an explicit template
     */
    public function __construct(
        array $twigRoots,
        array $assetRoots,
        private bool $autoDiscoveryEnabled,
        private ?LoggerInterface $logger = null,
        private bool $debug = false,
        array $classesWithExplicitTemplate = [],
    ) {
        $this->twigRoots = self::normalizeRoots($twigRoots);
        $this->assetRoots = self::normalizeRoots($assetRoots);
        $this->classesWithExplicitTemplate = array_fill_keys($classesWithExplicitTemplate, true);
    }

    /**
     * @throws InvalidArgumentException when an #[Asset] of the class is invalid
     */
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
        $problems = [];
        $fileName = $reflectionClass->getFileName();

        // Internal classes have no file, hence no siblings to discover.
        if ($this->autoDiscoveryEnabled && false !== $fileName) {
            $basePath = dirname($fileName) . DIRECTORY_SEPARATOR . $reflectionClass->getShortName();

            $assets = [...$assets, ...$this->findCoLocatedAssets($basePath, 'class', $problems)];

            if (!isset($this->classesWithExplicitTemplate[$class])) {
                $template = $this->findCoLocatedTemplate($basePath . '.html.twig', $problems);
            }
        }

        $this->logProblems('Component', $class, $problems);

        return $this->classCache[$class] = new ComponentMetadata($assets, $template, $problems);
    }

    /**
     * Resolves an anonymous component from its template, as Twig Components
     * names it (`ComponentMetadata::getTemplate()`): the stylesheet and script
     * named after the template file, next to it. The template itself is the
     * one Twig Components renders, so the returned metadata names none.
     *
     * A template no Twig path holds (one from another Twig loader) has no
     * file, hence nothing next to it.
     */
    public function resolveAnonymous(string $template): ComponentMetadata
    {
        if (isset($this->templateCache[$template])) {
            return $this->templateCache[$template];
        }

        $file = $this->autoDiscoveryEnabled ? $this->locateTemplate($template) : null;

        if (null === $file || !str_ends_with($file, '.html.twig')) {
            return $this->templateCache[$template] = new ComponentMetadata();
        }

        $problems = [];
        $assets = $this->findCoLocatedAssets(substr($file, 0, -\strlen('.html.twig')), 'template', $problems);

        $this->logProblems('Anonymous component', $template, $problems);

        return $this->templateCache[$template] = new ComponentMetadata($assets, null, $problems);
    }

    /**
     * The file a Twig name stands for, looked up in the Twig paths the way the
     * Twig filesystem loader does: `@namespace/…` in the paths of that
     * namespace, anything else in those of the main namespace, the first path
     * holding the file winning.
     *
     * @return string|null the real path; null when no Twig path holds the file
     */
    public function locateTemplate(string $template): ?string
    {
        $name = (string) preg_replace('#/{2,}#', '/', str_replace('\\', '/', $template));
        $namespace = '';

        if (str_starts_with($name, '@')) {
            $position = strpos($name, '/');
            if (false === $position) {
                return null;
            }

            $namespace = substr($name, 1, $position - 1);
            $name = substr($name, $position + 1);
        }

        $name = ltrim($name, '/');

        foreach ($this->twigRoots as [$root, $rootNamespace]) {
            if ($rootNamespace === $namespace && is_file($root . $name)) {
                $realPath = realpath($root . $name);

                return false === $realPath ? null : $realPath;
            }
        }

        return null;
    }

    /**
     * The stylesheet and script next to a component, named after it.
     *
     * @param string           $basePath the path of the component files without their extension
     * @param 'class'|'template' $owner  what the files are next to, for the problem reported
     * @param list<SdcProblem> $problems receives the reason a file that exists is left out
     *
     * @return list<SdcComponentAsset>
     */
    private function findCoLocatedAssets(string $basePath, string $owner, array &$problems): array
    {
        $assets = [];

        foreach (['css', 'js'] as $ext) {
            $path = $this->findCoLocatedAsset($basePath . '.' . $ext, $owner, $problems);
            if (null !== $path) {
                $assets[] = [
                    'path' => $path,
                    'type' => $ext,
                    'priority' => 0,
                    'attributes' => [],
                    'source' => ComponentMetadata::SOURCE_DISCOVERED,
                ];
            }
        }

        return $assets;
    }

    /**
     * @param list<SdcProblem> $problems
     */
    private function logProblems(string $kind, string $component, array $problems): void
    {
        if (!$this->debug) {
            return;
        }

        foreach ($problems as $problem) {
            $this->logger?->warning($kind . ' "{component}": ' . $problem['message'], ['component' => $component] + $problem['context']);
        }
    }

    /**
     * @param ReflectionClass<object> $reflectionClass
     *
     * @return list<SdcComponentAsset>
     *
     * @throws InvalidArgumentException when an #[Asset] of the class is invalid
     */
    private function collectExplicitAssets(ReflectionClass $reflectionClass): array
    {
        $assets = [];

        foreach ($reflectionClass->getAttributes(Asset::class) as $attribute) {
            try {
                $asset = $attribute->newInstance();
            } catch (InvalidArgumentException $e) {
                // The attribute does not know the class it is on.
                throw new InvalidArgumentException(\sprintf('An #[Asset] of the component "%s" is invalid: %s', $reflectionClass->getName(), $e->getMessage()), 0, $e);
            }

            // An #[Asset] without a path, and so without a type, loads nothing.
            if (null === $asset->path || null === $asset->type) {
                continue;
            }

            $assets[] = [
                'path' => $asset->path,
                'type' => $asset->type,
                'priority' => $asset->priority,
                'attributes' => $asset->attributes,
                'source' => ComponentMetadata::SOURCE_ASSET_ATTRIBUTE,
            ];
        }

        foreach ($reflectionClass->getAttributes(AsSdcComponent::class) as $attribute) {
            $sdcComponent = $attribute->newInstance();

            if ($sdcComponent->css) {
                $assets[] = [
                    'path' => $sdcComponent->css,
                    'type' => Asset::CSS,
                    'priority' => 0,
                    'attributes' => [],
                    'source' => ComponentMetadata::SOURCE_SDC_COMPONENT_ATTRIBUTE,
                ];
            }

            if ($sdcComponent->js) {
                $assets[] = [
                    'path' => $sdcComponent->js,
                    'type' => Asset::JS,
                    'priority' => 0,
                    'attributes' => [],
                    'source' => ComponentMetadata::SOURCE_SDC_COMPONENT_ATTRIBUTE,
                ];
            }
        }

        return $assets;
    }

    /**
     * @param 'class'|'template' $owner
     * @param list<SdcProblem>   $problems receives the reason a file that exists is left out
     *
     * @return string|null the AssetMapper logical path of the file; null when
     *                     there is no such file or it is outside every AssetMapper path
     */
    private function findCoLocatedAsset(string $file, string $owner, array &$problems): ?string
    {
        if (!file_exists($file)) {
            return null;
        }

        $realFile = realpath($file) ?: $file;

        // The first matching path, in configuration order, as AssetMapper itself
        // names a file (AssetMapperRepository::findLogicalPath()).
        foreach ($this->assetRoots as [$root, $namespace]) {
            if (str_starts_with($realFile, $root)) {
                return self::prefix($namespace, substr($realFile, strlen($root)));
            }
        }

        $problems[] = self::problem(
            'class' === $owner
                ? '"{file}" next to the class is outside every AssetMapper path ({roots}), so it is not loaded with the component. Move the component into the directory configured as "ux_sdc.ux_components_dir", add its directory to "framework.asset_mapper.paths", or reference the file by its AssetMapper logical path with #[Asset].'
                : '"{file}" next to the template is outside every AssetMapper path ({roots}), so it is not loaded with the component. Move the component into the directory configured as "ux_sdc.ux_components_dir", or add its directory to "framework.asset_mapper.paths".',
            $realFile,
            $this->assetRoots,
        );

        return null;
    }

    /**
     * @param list<SdcProblem> $problems receives the reason a file that exists is left out
     *
     * @return string|null the Twig name of the file; null when there is no such
     *                     file or it is outside every Twig path
     */
    private function findCoLocatedTemplate(string $file, array &$problems): ?string
    {
        if (!file_exists($file)) {
            return null;
        }

        $realFile = realpath($file) ?: $file;

        // A path of the main namespace wins, so that a file under
        // `ux_components_dir` keeps the name it has without a namespace; within
        // the same kind of path, the shortest name.
        foreach ([true, false] as $mainNamespace) {
            $name = null;

            foreach ($this->twigRoots as [$root, $namespace]) {
                if (('' === $namespace) !== $mainNamespace || !str_starts_with($realFile, $root)) {
                    continue;
                }

                $candidate = self::prefix($mainNamespace ? '' : '@' . $namespace, substr($realFile, strlen($root)));
                if (null === $name || strlen($candidate) < strlen($name)) {
                    $name = $candidate;
                }
            }

            if (null !== $name) {
                return $name;
            }
        }

        $problems[] = self::problem(
            'the template "{file}" next to the class is outside every Twig path ({roots}), so it is not used. Move the component into the directory configured as "ux_sdc.ux_components_dir", or name its template explicitly with the "template" argument of #[AsTwigComponent] or #[AsSdcComponent].',
            $realFile,
            $this->twigRoots,
        );

        return null;
    }

    /**
     * @param list<array{string, string}> $roots
     *
     * @return SdcProblem
     */
    private static function problem(string $message, string $file, array $roots): array
    {
        $paths = array_map(static fn (array $root): string => rtrim($root[0], DIRECTORY_SEPARATOR), $roots);

        return [
            'message' => $message,
            'context' => [
                'file' => $file,
                'roots' => [] === $paths ? 'none configured' : implode(', ', array_unique($paths)),
            ],
        ];
    }

    /**
     * @param array<int|string, string|null> $roots
     *
     * @return list<array{string, string}>
     */
    private static function normalizeRoots(array $roots): array
    {
        $normalized = [];

        foreach ($roots as $path => $namespace) {
            // A list of directories, or a map of directory => namespace.
            if (\is_int($path)) {
                $path = (string) $namespace;
                $namespace = '';
            }

            $realPath = '' === $path ? false : realpath($path);
            if (false !== $realPath) {
                $normalized[] = [rtrim($realPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, (string) $namespace];
            }
        }

        return $normalized;
    }

    /**
     * Joins a namespace and a relative path the way Twig and AssetMapper do,
     * with forward slashes.
     */
    private static function prefix(string $namespace, string $relativePath): string
    {
        $relativePath = str_replace('\\', '/', $relativePath);

        return '' === $namespace ? $relativePath : rtrim($namespace, '/') . '/' . $relativePath;
    }
}
