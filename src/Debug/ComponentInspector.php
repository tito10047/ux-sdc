<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Debug;

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\UX\StimulusBundle\AssetMapper\ControllersMapGenerator;
use Symfony\UX\TwigComponent\ComponentFactory;
use Symfony\UX\TwigComponent\ComponentMetadata as TwigComponentMetadata;
use Symfony\UX\TwigComponent\ComponentTemplateFinderInterface;
use Tito10047\UX\Sdc\Runtime\SdcMetadataRegistry;
use Tito10047\UX\Sdc\Service\AssetPath;
use Tito10047\UX\Sdc\Service\AssetType;
use Tito10047\UX\Sdc\Service\ComponentMetadata;
use Tito10047\UX\Sdc\Service\ComponentMetadataResolver;
use Tito10047\UX\Sdc\Service\StimulusControllerNameResolver;
use Twig\Environment;
use Twig\Error\Error as TwigError;
use Twig\Token;

/**
 * Describes the components of the application as the bundle renders them, for
 * `debug:sdc`.
 *
 * The metadata comes from where the application's own pages get it: the
 * build-time cache with `ux_sdc.metadata_cache` on, the resolver otherwise. It
 * is then checked against the services that serve it — Twig, AssetMapper,
 * StimulusBundle — for what would keep a part of the component from loading.
 *
 * The list of components is the `twig.component` services and the templates
 * of anonymous components, collected by AssetComponentCompilerPass; their
 * names and templates come from Twig Components itself.
 *
 * @phpstan-import-type SdcComponentAsset from ComponentMetadata
 * @phpstan-import-type ReportedAsset from ComponentReport
 * @phpstan-import-type ReportedRoute from ComponentReport
 * @phpstan-import-type ReportedStimulusController from ComponentReport
 *
 * @internal
 */
final class ComponentInspector
{
    /** @var array<string, true>|null names of the controllers StimulusBundle maps */
    private ?array $stimulusControllers = null;

    /** @var array<string, list<ReportedRoute>>|null routes by the namespace of their controller */
    private ?array $routesByNamespace = null;

    /** @var array<string, true> */
    private readonly array $classesWithExplicitTemplate;

    /** @var array<string, string|null>|null component name => class, null for an anonymous component */
    private ?array $components = null;

    /** @var array<string, string> name of an anonymous component => its template */
    private array $anonymousComponents = [];

    /**
     * @param ComponentFactory             $componentFactory            names a component class and its template the way Twig Components does
     * @param bool                         $metadataCache               whether `ux_sdc.metadata_cache` resolved to true
     * @param ControllersMapGenerator|null $controllersMapGenerator     absent without StimulusBundle or without AssetMapper
     * @param list<string>                     $componentClasses            the classes of the `twig.component` services
     * @param list<string>                     $classesWithExplicitTemplate component classes registered with an explicit template
     * @param ComponentTemplateFinderInterface $templateFinder              finds the template of an anonymous component the way Twig Components does
     * @param array<string, list<string>>      $anonymousTemplates          template of an anonymous component => the names it may be rendered as
     */
    public function __construct(
        private readonly ComponentFactory $componentFactory,
        private readonly SdcMetadataRegistry $metadataRegistry,
        private readonly ComponentMetadataResolver $metadataResolver,
        private readonly bool $metadataCache,
        private readonly Environment $twig,
        private readonly AssetMapperInterface $assetMapper,
        private readonly StimulusControllerNameResolver $controllerNameResolver,
        private readonly ?ControllersMapGenerator $controllersMapGenerator,
        private readonly ?RouterInterface $router,
        private readonly array $componentClasses,
        array $classesWithExplicitTemplate,
        private readonly ComponentTemplateFinderInterface $templateFinder,
        private readonly array $anonymousTemplates,
    ) {
        $this->classesWithExplicitTemplate = array_fill_keys($classesWithExplicitTemplate, true);
    }

    public function usesMetadataCache(): bool
    {
        return $this->metadataCache;
    }

    /**
     * @return array<string, string|null> component name => class, null for an anonymous component, sorted by name
     */
    public function listComponents(): array
    {
        if (null !== $this->components) {
            return $this->components;
        }

        // The classes first: Twig Components gives a class precedence over a
        // template of the same name.
        $components = [];
        foreach ($this->componentClasses as $class) {
            $components[$this->componentFactory->metadataFor($class)->getName()] = $class;
        }

        // Each anonymous template under the first of its names Twig Components
        // resolves to it.
        foreach ($this->anonymousTemplates as $template => $names) {
            foreach ($names as $name) {
                if (!\array_key_exists($name, $components) && $template === $this->templateFinder->findAnonymousComponentTemplate($name)) {
                    $components[$name] = null;
                    $this->anonymousComponents[$name] = $template;

                    break;
                }
            }
        }

        ksort($components);

        return $this->components = $components;
    }

    /**
     * @param string $name a component name, as listComponents() returns it
     */
    public function inspect(string $name): ComponentReport
    {
        $class = $this->listComponents()[$name] ?? null;

        if (null === $class) {
            return $this->inspectAnonymous($name, $this->anonymousComponents[$name] ?? throw new \InvalidArgumentException(\sprintf('Unknown component "%s".', $name)));
        }

        $component = $this->componentFactory->metadataFor($class);
        $metadata = $this->metadataCache
            ? $this->metadataRegistry->getComponentMetadata($class)
            : $this->metadataResolver->resolve($class);

        $problems = $metadata->getProblemMessages();

        [$template, $templateOrigin, $templateFound] = $this->describeTemplate($component, $metadata, $problems);

        $assets = [];
        foreach ($metadata->assets as $asset) {
            $assets[] = $this->describeAsset($asset, $problems);
        }

        $stimulusController = $this->describeStimulusController($component, $templateFound ? $template : null, $problems);

        return new ComponentReport(
            $component->getName(),
            $class,
            $template,
            $templateOrigin,
            $templateFound,
            $assets,
            $stimulusController,
            $this->findRoutes($class),
            $problems,
        );
    }

    /**
     * An anonymous component has its template, and the assets next to it. It
     * has no routes, and gets no `controller` from the bundle.
     */
    private function inspectAnonymous(string $name, string $template): ComponentReport
    {
        $metadata = $this->metadataCache
            ? $this->metadataRegistry->getAnonymousComponentMetadata($template)
            : $this->metadataResolver->resolveAnonymous($template);

        $problems = $metadata->getProblemMessages();

        $assets = [];
        foreach ($metadata->assets as $asset) {
            $assets[] = $this->describeAsset($asset, $problems);
        }

        // A `controller` the template reads is one it is passed as a prop:
        // there is no name of the bundle's to check it against.
        $used = $this->templateUsesController($template, $problems);

        return new ComponentReport(
            $name,
            null,
            $template,
            ComponentReport::TEMPLATE_ANONYMOUS,
            // Twig Components found it when the list was made.
            true,
            $assets,
            ['name' => null, 'set_by_component' => false, 'used' => $used, 'registered' => null],
            [],
            $problems,
        );
    }

    /**
     * @param list<string> $problems
     *
     * @return array{string, ComponentReport::TEMPLATE_*, bool|null}
     */
    private function describeTemplate(TwigComponentMetadata $component, ComponentMetadata $metadata, array &$problems): array
    {
        if (null !== $metadata->template) {
            $template = $metadata->template;
            $origin = ComponentReport::TEMPLATE_CO_LOCATED;
        } elseif (\is_string($method = $component->get('template_from_method'))) {
            return [$method . '()', ComponentReport::TEMPLATE_FROM_METHOD, null];
        } else {
            $template = $component->getTemplate();
            $origin = isset($this->classesWithExplicitTemplate[$component->getClass()])
                ? ComponentReport::TEMPLATE_EXPLICIT
                : ComponentReport::TEMPLATE_DEFAULT;
        }

        $found = $this->twig->getLoader()->exists($template);

        if (!$found) {
            $problems[] = match ($origin) {
                ComponentReport::TEMPLATE_CO_LOCATED => \sprintf('The template "%s" was next to the class when the container was built, and no longer exists. Run "bin/console cache:clear".', $template),
                ComponentReport::TEMPLATE_EXPLICIT => \sprintf('The template "%s" named in the registration of the component does not exist.', $template),
                ComponentReport::TEMPLATE_DEFAULT => \sprintf('The component has no template: none next to the class can be used, and "%s", which Twig Components looks up by default, does not exist. Create a .html.twig file named after the class next to it, or name the template with the "template" argument of #[AsSdcComponent] or #[AsTwigComponent].%s', $template, $this->metadataCache ? ' With "ux_sdc.metadata_cache" on, a file added next to the class is only seen after "bin/console cache:clear".' : ''),
            };
        }

        return [$template, $origin, $found];
    }

    /**
     * @param SdcComponentAsset $asset
     * @param list<string>      $problems
     *
     * @return ReportedAsset
     */
    private function describeAsset(array $asset, array &$problems): array
    {
        $path = $asset['path'];
        // As the render listeners register it.
        $type = AssetType::resolve($asset['type'], $path);

        if (!AssetType::isLoaded($type)) {
            $problems[] = \sprintf('The asset "%s" (%s) has the type "%s", so it is not loaded: only "css" and "js" are.', $path, $asset['source'], $type);
        }

        $mapped = null;
        $publicPath = null;
        if (!AssetPath::isUrl($path)) {
            $publicPath = $this->assetMapper->getAsset($path)?->publicPath;
            $mapped = null !== $publicPath;

            if (!$mapped) {
                $problems[] = \sprintf('The asset "%s" (%s) is not in the asset map, so the page links to it as it is and it will most likely not load. Use its AssetMapper logical path (see "bin/console debug:asset-map"), or a URL.%s', $path, $asset['source'], ComponentMetadata::SOURCE_DISCOVERED === $asset['source'] && $this->metadataCache ? ' It was next to the component when the container was built: run "bin/console cache:clear".' : '');
            }
        }

        return [
            'path' => $path,
            'public_path' => $mapped ? $publicPath : (null === $mapped ? $path : null),
            'type' => $type,
            'source' => $asset['source'],
            'priority' => $asset['priority'],
            'attributes' => $asset['attributes'],
            'mapped' => $mapped,
        ];
    }

    /**
     * The `controller` variable the bundle gives the template, and whether
     * StimulusBundle has a controller of that name. A template that uses the
     * variable is expected to need that controller.
     *
     * @param list<string> $problems
     *
     * @return ReportedStimulusController
     */
    private function describeStimulusController(TwigComponentMetadata $component, ?string $template, array &$problems): array
    {
        $class = $component->getClass();
        $used = null !== $template && $this->templateUsesController($template, $problems);

        if ($this->controllerNameResolver->isExposedByComponent($class, $component->isPublicPropsExposed())) {
            return ['name' => null, 'set_by_component' => true, 'used' => $used, 'registered' => null];
        }

        $controller = $this->controllerNameResolver->resolve($class);

        if (null === $controller) {
            if ($used) {
                $problems[] = \sprintf('The template "%s" uses the variable "controller", which the bundle only sets for a class in "ux_sdc.component_namespace".', $template);
            }

            return ['name' => null, 'set_by_component' => false, 'used' => $used, 'registered' => null];
        }

        $controllers = $this->loadStimulusControllers();
        $registered = null === $controllers ? null : isset($controllers[$controller]);

        if ($used && false === $registered) {
            $problems[] = \sprintf('The template "%s" uses the Stimulus controller "%s", which StimulusBundle does not know. Put a controller file named after the class next to it ("%s_controller.js"): StimulusBundle names a controller after its path, and the case matters.', $template, $controller, (new \ReflectionClass($class))->getShortName());
        }

        return ['name' => $controller, 'set_by_component' => false, 'used' => $used, 'registered' => $registered];
    }

    /**
     * Whether the template reads a variable named `controller`: the name
     * outside of an attribute access (`x.controller`), an assignment
     * (`{% set controller = … %}`) or a key or named argument (`controller: …`).
     * Only the template itself is read, not those it extends or includes.
     *
     * @param list<string> $problems
     */
    private function templateUsesController(string $template, array &$problems): bool
    {
        try {
            $stream = $this->twig->tokenize($this->twig->getLoader()->getSourceContext($template));
        } catch (TwigError $error) {
            $problems[] = \sprintf('The template "%s" could not be read: %s', $template, $error->getRawMessage());

            return false;
        }

        $previous = null;
        while (!$stream->isEOF()) {
            $token = $stream->next();
            $next = $stream->getCurrent();

            // The attribute access is a punctuation token up to Twig 3.20, an
            // operator from 3.21 on.
            if ($token->test(Token::NAME_TYPE, 'controller')
                && !$previous?->test(Token::PUNCTUATION_TYPE, ['.', '?.'])
                && !$previous?->test(Token::OPERATOR_TYPE, ['.', '?.'])
                && !$previous?->test(Token::NAME_TYPE, 'set')
                && !$next->test(Token::PUNCTUATION_TYPE, ':')
                && !$next->test(Token::OPERATOR_TYPE, '=')
            ) {
                return true;
            }

            $previous = $token;
        }

        return false;
    }

    /**
     * @return array<string, true>|null null when StimulusBundle does not map controllers through AssetMapper
     */
    private function loadStimulusControllers(): ?array
    {
        if (null === $this->controllersMapGenerator) {
            return null;
        }

        return $this->stimulusControllers ??= array_fill_keys(array_keys($this->controllersMapGenerator->getControllersMap()), true);
    }

    /**
     * Routes whose controller is a class of the namespace of the component
     * class, such as the action make:sdc-component --action puts next to it.
     *
     * @return list<ReportedRoute>
     */
    private function findRoutes(string $class): array
    {
        if (null === $this->router) {
            return [];
        }

        if (null === $this->routesByNamespace) {
            $this->routesByNamespace = [];

            foreach ($this->router->getRouteCollection()->all() as $name => $route) {
                $controller = $route->getDefault('_controller');
                if (!\is_string($controller)) {
                    continue;
                }

                // "Class::method", or "Class" for an invokable controller. A
                // service ID without a namespace belongs to no component.
                $namespace = self::namespaceOf(explode('::', $controller, 2)[0]);
                if ('' !== $namespace) {
                    $this->routesByNamespace[$namespace][] = ['name' => $name, 'path' => $route->getPath()];
                }
            }
        }

        return $this->routesByNamespace[self::namespaceOf($class)] ?? [];
    }

    private static function namespaceOf(string $class): string
    {
        $position = strrpos($class, '\\');

        return false === $position ? '' : substr($class, 0, $position);
    }
}
