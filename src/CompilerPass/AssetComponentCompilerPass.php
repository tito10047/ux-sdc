<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\CompilerPass;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\Filesystem\Filesystem;
use Tito10047\UX\Sdc\Debug\ComponentInspector;
use Tito10047\UX\Sdc\ImportMap\ComponentImportMapEntries;
use Tito10047\UX\Sdc\Runtime\SdcMetadataRegistry;
use Tito10047\UX\Sdc\Service\ComponentMetadata;
use Tito10047\UX\Sdc\Service\ComponentMetadataResolver;
use Tito10047\UX\Sdc\Twig\ComponentDirectoryTemplateFinder;

/**
 * Collects what the resolver needs to know about the application — its Twig
 * paths, its AssetMapper paths and the components registered with an explicit
 * template — for the runtime resolver and for the build-time one, hands the
 * components, anonymous ones included, to `debug:sdc`, and, with
 * `ux_sdc.metadata_cache` on, dumps the metadata of every component into
 * `sdc_metadata.php`.
 *
 * The paths are read from the services they configure, after every extension
 * has loaded: the Twig filesystem loader and the AssetMapper repository. That
 * is the list each of them actually uses — `twig.default_path`, `twig.paths`,
 * bundle templates, `framework.asset_mapper.paths` and bundle public
 * directories, normalised and merged — rather than a second reading of the
 * raw configuration.
 *
 * @phpstan-import-type SdcMetadataCache from SdcMetadataRegistry
 * @phpstan-import-type SdcComponentAsset from ComponentMetadata
 * @phpstan-import-type SdcProblem from ComponentMetadata
 *
 * @internal
 */
final class AssetComponentCompilerPass implements CompilerPassInterface
{
    /**
     * Whether the metadata is resolved at build time, as resolved by SdcExtension
     * from `ux_sdc.metadata_cache`: always a boolean.
     *
     * A build parameter (leading dot): it is not dumped into the compiled container.
     */
    public const METADATA_CACHE_PARAMETER = '.ux_sdc.metadata_cache';

    /**
     * Below the default priority, after Twig Components' TwigComponentPass has
     * named the component classes and their templates.
     */
    public const PRIORITY = -8;

    private const TWIG_LOADER = 'twig.loader.native_filesystem';
    private const ASSET_MAPPER_REPOSITORY = 'asset_mapper.repository';
    private const COMPONENT_FACTORY = 'ux.twig_component.component_factory';
    private const COMPONENT_TEMPLATE_FINDER = 'ux.twig_component.component_template_finder';

    /**
     * The directory of a bundle's Twig path Twig Components looks anonymous
     * components up in: `@Acme/components/Button.html.twig` is `<twig:Acme:Button>`.
     */
    private const BUNDLE_ANONYMOUS_DIRECTORY = 'components';

    public function process(ContainerBuilder $container): void
    {
        $twigRoots = $this->collectTwigRoots($container);
        $assetRoots = $this->collectAssetMapperRoots($container);
        $componentClasses = $this->collectComponentClasses($container);
        $classesWithExplicitTemplate = $this->collectClassesWithExplicitTemplate($container);

        // Both pipelines resolve against the same roots and registrations. Only
        // a compiler pass sees them complete, which is why the runtime resolver
        // gets them from here and not from SdcExtension.
        if ($container->hasDefinition(ComponentMetadataResolver::class)) {
            $container->getDefinition(ComponentMetadataResolver::class)
                ->setArgument('$twigRoots', $twigRoots)
                ->setArgument('$assetRoots', $assetRoots)
                ->setArgument('$classesWithExplicitTemplate', $classesWithExplicitTemplate);
        }

        $metadataCache = true === $container->getParameter(self::METADATA_CACHE_PARAMETER);

        // Validated as a boolean node by the bundle configuration.
        $autoDiscovery = (bool) $container->getParameter('ux_sdc.auto_discovery');

        // With the metadata cache on, files the resolver has to leave out are
        // reported to the compiler log, the build-time counterpart of the
        // `logger` service, which does not exist yet. The kernel keeps that log
        // for debug containers only (and the profiler shows it), so the resolver
        // is told it runs in debug: in a production build the report goes
        // nowhere without any further check. Without the cache, the runtime
        // resolver reports them on render; this one only names templates.
        $resolver = new ComponentMetadataResolver(
            $twigRoots,
            $assetRoots,
            $autoDiscovery,
            $metadataCache ? new CompilerLogLogger($container, $this) : null,
            $metadataCache,
            $classesWithExplicitTemplate,
        );

        $anonymousTemplates = $this->collectAnonymousTemplates($container, $twigRoots, $resolver, $componentClasses);

        if ($container->hasDefinition(ComponentInspector::class)) {
            $container->getDefinition(ComponentInspector::class)
                ->setArgument('$componentClasses', $componentClasses)
                ->setArgument('$classesWithExplicitTemplate', $classesWithExplicitTemplate)
                ->setArgument('$anonymousTemplates', $anonymousTemplates);
        }

        if ($container->hasDefinition(ComponentImportMapEntries::class) && $container->hasDefinition(ComponentMetadataResolver::class)) {
            // A resolver of its own, without a logger: the files it has to
            // leave out are reported when their component renders, not on
            // every page that has an import map.
            $silentResolver = (clone $container->getDefinition(ComponentMetadataResolver::class))
                ->setArgument('$logger', null)
                ->clearTags();

            $container->getDefinition(ComponentImportMapEntries::class)
                ->setArgument('$metadataResolver', $silentResolver)
                ->setArgument('$componentClasses', $componentClasses)
                ->setArgument('$anonymousTemplates', array_keys($anonymousTemplates));
        }

        if (!$container->hasDefinition(SdcMetadataRegistry::class)) {
            return;
        }

        $registryDefinition = $container->getDefinition(SdcMetadataRegistry::class);
        $cachePath = $container->getParameterBag()->resolveValue($registryDefinition->getArgument('$cachePath'));

        if (!$metadataCache) {
            // The metadata is resolved on render. A file left behind by an earlier
            // build with the cache on would describe components as they were then;
            // the registry does not read it, but nothing should be left to mislead.
            if (file_exists($cachePath)) {
                unlink($cachePath);
            }

            return;
        }

        $this->dumpCache($cachePath, [
            'components' => $this->resolveComponents($componentClasses, $resolver),
            'anonymous' => $this->resolveAnonymousComponents(array_keys($anonymousTemplates), $resolver),
        ]);
    }

    /**
     * The directories of the Twig filesystem loader, in the order Twig looks
     * templates up, as registered by TwigBundle: `addPath($path)` for the main
     * namespace, `addPath($path, $namespace)` for any other.
     *
     * @return array<string, string|null> existing directories, as real paths, => namespace (null for the main one)
     */
    private function collectTwigRoots(ContainerBuilder $container): array
    {
        if (!$container->hasDefinition(self::TWIG_LOADER)) {
            return [];
        }

        $roots = [];
        foreach ($container->getDefinition(self::TWIG_LOADER)->getMethodCalls() as [$method, $arguments]) {
            if ('addPath' !== $method || !isset($arguments[0]) || !\is_string($arguments[0])) {
                continue;
            }

            $namespace = isset($arguments[1]) && \is_string($arguments[1]) && '' !== $arguments[1] ? $arguments[1] : null;
            $realPath = $this->resolveDirectory($container, $arguments[0]);

            // `ux_sdc.ux_components_dir` is registered twice, in the main
            // namespace and as @component: the main namespace is kept, so its
            // templates keep the names they have always had.
            if (null !== $realPath && (!\array_key_exists($realPath, $roots) || null === $namespace)) {
                $roots[$realPath] = $namespace;
            }
        }

        return $roots;
    }

    /**
     * The directories of the AssetMapper repository, in the order AssetMapper
     * looks assets up. Its first constructor argument has been the map of
     * directory => namespace FrameworkBundle builds from
     * `framework.asset_mapper.paths` since AssetMapper exists.
     *
     * @return array<string, string> existing directories, as real paths, => namespace ('' for none)
     */
    private function collectAssetMapperRoots(ContainerBuilder $container): array
    {
        // Absent when AssetMapper is disabled.
        if (!$container->hasDefinition(self::ASSET_MAPPER_REPOSITORY)) {
            return [];
        }

        $arguments = $container->getDefinition(self::ASSET_MAPPER_REPOSITORY)->getArguments();
        $paths = $arguments[0] ?? $arguments['$paths'] ?? null;
        if (!\is_array($paths)) {
            throw new LogicException(\sprintf('UX SDC cannot read the AssetMapper paths: the first argument of the "%s" service is not the array of paths it expects (%s). This version of AssetMapper is not supported.', self::ASSET_MAPPER_REPOSITORY, get_debug_type($paths)));
        }

        $roots = [];
        foreach ($paths as $path => $namespace) {
            if (!\is_string($path) || !\is_string($namespace)) {
                continue;
            }

            $realPath = $this->resolveDirectory($container, $path);
            if (null !== $realPath && !\array_key_exists($realPath, $roots)) {
                $roots[$realPath] = $namespace;
            }
        }

        return $roots;
    }

    /**
     * Components whose registration names their template: the `template`
     * argument of #[AsTwigComponent] and its subclasses, or of a `twig.component`
     * tag registered by hand. TwigComponent only fills in a default template
     * for a tag that names none, and does so in its own copy of the tag, so the
     * tags still say what the application chose.
     *
     * @return list<string>
     */
    private function collectClassesWithExplicitTemplate(ContainerBuilder $container): array
    {
        $classes = [];

        foreach ($container->findTaggedServiceIds('twig.component') as $id => $tags) {
            foreach ($tags as $tag) {
                if (!isset($tag['template']) && !isset($tag['template_from_method'])) {
                    continue;
                }

                $class = $container->getParameterBag()->resolveValue($container->getDefinition($id)->getClass());
                if (\is_string($class)) {
                    $classes[] = $class;
                }

                break;
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * Resolves a configured directory the way Twig and AssetMapper do: relative
     * to the project directory unless absolute.
     *
     * @return string|null the real path; null when the directory does not exist
     */
    private function resolveDirectory(ContainerBuilder $container, string $path): ?string
    {
        $parameterBag = $container->getParameterBag();
        $resolved = $container->resolveEnvPlaceholders($parameterBag->unescapeValue($parameterBag->resolveValue($path)), true);
        if (!\is_string($resolved) || '' === $resolved) {
            return null;
        }

        if (!(new Filesystem())->isAbsolutePath($resolved) && $container->hasParameter('kernel.project_dir')) {
            $projectDir = $container->getParameter('kernel.project_dir');
            if (\is_string($projectDir)) {
                $resolved = $projectDir . '/' . $resolved;
            }
        }

        $realPath = realpath($resolved);

        return false !== $realPath && is_dir($realPath) ? $realPath : null;
    }

    /**
     * The classes of the `twig.component` services that exist.
     *
     * @return list<class-string>
     */
    private function collectComponentClasses(ContainerBuilder $container): array
    {
        $classes = [];

        foreach (array_keys($container->findTaggedServiceIds('twig.component')) as $id) {
            $class = $container->getParameterBag()->resolveValue($container->getDefinition($id)->getClass());

            if (\is_string($class) && class_exists($class)) {
                $classes[] = $class;
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * The templates of anonymous components: every `.html.twig` file of the
     * directories Twig Components looks anonymous components up in, under the
     * name it gives the template, with the names it may know the component by.
     *
     *  - `twig_component.anonymous_template_directory` in each Twig path of
     *    the main namespace: `components/Alert.html.twig` is `<twig:Alert>`;
     *  - `components/` in each namespaced Twig path:
     *    `@Acme/components/Button.html.twig` is `<twig:Acme:Button>`;
     *  - `ux_sdc.ux_components_dir`, where ComponentDirectoryTemplateFinder
     *    looks them up: `Alert/Alert.html.twig` is `<twig:Alert:Alert>`.
     *
     * Of the names, the one that resolves to the template is only known once
     * Twig Components runs (`index.html.twig` is the template of its directory
     * from Twig Components 2.32 on, a template may be shadowed by another, a
     * class may take the name), so `debug:sdc` checks them then. Here, only the
     * templates of classes are left out: the template named in the
     * registration of a component class or given to it by Twig Components by
     * default, the `.html.twig` file named after a component class, next to it
     * — used by the class or not, it belongs to the class — and, in the
     * component directory, any template next to a PHP file of the same name,
     * such as the page of a controller (see ComponentDirectoryTemplateFinder,
     * which does not find them either).
     *
     * @param array<string, string|null> $twigRoots        existing directories, as real paths, => namespace (null for the main one)
     * @param list<class-string>         $componentClasses
     *
     * @return array<string, list<string>> template name => component names, the most likely first
     */
    private function collectAnonymousTemplates(ContainerBuilder $container, array $twigRoots, ComponentMetadataResolver $resolver, array $componentClasses): array
    {
        $directories = [];

        $anonymousDirectory = $this->readAnonymousTemplateDirectory($container);
        foreach ($twigRoots as $root => $namespace) {
            if (null === $namespace) {
                // Joined as Twig Components joins it: the directory, then "/".
                $directories[] = [$root, $anonymousDirectory, rtrim($anonymousDirectory, '/') . '/', '', false];
            } else {
                $directories[] = [$root, self::BUNDLE_ANONYMOUS_DIRECTORY, '@' . $namespace . '/' . self::BUNDLE_ANONYMOUS_DIRECTORY . '/', $namespace . ':', false];
            }
        }

        $componentsDir = $container->hasParameter('ux_sdc.ux_components_dir') ? $container->getParameter('ux_sdc.ux_components_dir') : null;
        $componentsDir = \is_string($componentsDir) ? $this->resolveDirectory($container, $componentsDir) : null;
        // Looked up only when the directory is a Twig path, under the namespace SdcExtension gives it.
        if (null !== $componentsDir && \array_key_exists($componentsDir, $twigRoots)) {
            $directories[] = [$componentsDir, '', '', '', true];
        }

        $classTemplates = $this->collectClassTemplateFiles($container, $resolver, $componentClasses);

        $templates = [];
        foreach ($directories as [$root, $directory, $templatePrefix, $namePrefix, $isComponentsDir]) {
            foreach (self::findTemplates($root . ('' === trim($directory, '/') ? '' : '/' . trim($directory, '/'))) as $relativePath) {
                $template = $templatePrefix . $relativePath;

                $file = $resolver->locateTemplate($template);
                if (null === $file || isset($classTemplates[$file]) || isset($templates[$template])) {
                    continue;
                }

                // The rule of ComponentDirectoryTemplateFinder, which is what
                // finds the templates of this directory at runtime.
                if ($isComponentsDir && ComponentDirectoryTemplateFinder::belongsToClass($root . '/' . $relativePath)) {
                    continue;
                }

                $name = $namePrefix . str_replace('/', ':', substr($relativePath, 0, -\strlen('.html.twig')));

                // From Twig Components 2.32 on, `Alert/index.html.twig` is `<twig:Alert>`.
                $templates[$template] = str_ends_with($name, ':index') && ':index' !== $name
                    ? [substr($name, 0, -\strlen(':index')), $name]
                    : [$name];
            }
        }

        return $templates;
    }

    /**
     * `twig_component.anonymous_template_directory`, as Twig Components hands
     * it to its template finder. Not set at all, which Twig Components 2.x
     * still accepts (deprecated), its finder looks in `components/` first.
     */
    private function readAnonymousTemplateDirectory(ContainerBuilder $container): string
    {
        if (!$container->hasDefinition(self::COMPONENT_TEMPLATE_FINDER)) {
            return 'components';
        }

        $directory = $container->getDefinition(self::COMPONENT_TEMPLATE_FINDER)->getArguments()[1] ?? null;
        if (null === $directory) {
            return 'components';
        }

        $directory = $container->getParameterBag()->resolveValue($directory);
        if (!\is_string($directory)) {
            throw new LogicException(\sprintf('UX SDC cannot read the directory of anonymous components: the second argument of the "%s" service is not the string it expects (%s). This version of Twig Components is not supported.', self::COMPONENT_TEMPLATE_FINDER, get_debug_type($directory)));
        }

        return $directory;
    }

    /**
     * The template files of the component classes, which no anonymous
     * component renders: the template Twig Components gives each class (from
     * the component configuration TwigComponentPass hands its factory), and
     * the `.html.twig` file named after the class, next to it.
     *
     * @param list<class-string> $componentClasses
     *
     * @return array<string, true> real paths
     */
    private function collectClassTemplateFiles(ContainerBuilder $container, ComponentMetadataResolver $resolver, array $componentClasses): array
    {
        $files = [];

        foreach ($componentClasses as $class) {
            $reflectionClass = new \ReflectionClass($class);
            $classFile = $reflectionClass->getFileName();
            if (false === $classFile) {
                continue;
            }

            $template = realpath(\dirname($classFile) . '/' . $reflectionClass->getShortName() . '.html.twig');
            if (false !== $template) {
                $files[$template] = true;
            }
        }

        if (!$container->hasDefinition(self::COMPONENT_FACTORY)) {
            return $files;
        }

        $config = $container->getDefinition(self::COMPONENT_FACTORY)->getArguments()[4] ?? null;
        if (!\is_array($config)) {
            throw new LogicException(\sprintf('UX SDC cannot read the templates of the component classes: the fifth argument of the "%s" service is not the component configuration it expects (%s). This version of Twig Components is not supported.', self::COMPONENT_FACTORY, get_debug_type($config)));
        }

        foreach ($config as $component) {
            $template = \is_array($component) && isset($component['template']) && \is_string($component['template'])
                ? $resolver->locateTemplate($component['template'])
                : null;

            if (null !== $template) {
                $files[$template] = true;
            }
        }

        return $files;
    }

    /**
     * @return list<string> the `.html.twig` files under the directory, relative to it, with forward slashes, sorted
     */
    private static function findTemplates(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $templates = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS));

        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && str_ends_with($file->getFilename(), '.html.twig')) {
                $templates[] = str_replace('\\', '/', substr($file->getPathname(), \strlen($directory) + 1));
            }
        }

        sort($templates);

        return $templates;
    }

    /**
     * @param list<string> $componentClasses
     *
     * @return array<string, array{assets: list<SdcComponentAsset>, template: string|null, problems: list<SdcProblem>}>
     */
    private function resolveComponents(array $componentClasses, ComponentMetadataResolver $resolver): array
    {
        $metadata = [];

        foreach ($componentClasses as $class) {
            $componentMetadata = $resolver->resolve($class);

            if (!$componentMetadata->isEmpty()) {
                $metadata[$class] = [
                    'assets' => $componentMetadata->assets,
                    'template' => $componentMetadata->template,
                    'problems' => $componentMetadata->problems,
                ];
            }
        }

        return $metadata;
    }

    /**
     * @param list<string> $templates
     *
     * @return array<string, array{assets: list<SdcComponentAsset>, problems: list<SdcProblem>}>
     */
    private function resolveAnonymousComponents(array $templates, ComponentMetadataResolver $resolver): array
    {
        $metadata = [];

        foreach ($templates as $template) {
            $componentMetadata = $resolver->resolveAnonymous($template);

            if (!$componentMetadata->isEmpty()) {
                $metadata[$template] = [
                    'assets' => $componentMetadata->assets,
                    'problems' => $componentMetadata->problems,
                ];
            }
        }

        return $metadata;
    }

    /**
     * @param SdcMetadataCache $data
     */
    private function dumpCache(string $path, array $data): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $content = '<?php return ' . var_export($data, true) . ';';
        file_put_contents($path, $content);
    }
}
