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

use Tito10047\UX\Sdc\Service\ComponentMetadata;

/**
 * What `debug:sdc` shows about one component: how the bundle renders it, and
 * what keeps any part of it from loading.
 *
 * `ReportedAsset` is an asset of the component: `path` as the metadata names
 * it, `public_path` as the page links to it (null when the asset map does not
 * know the logical path), `type` (`css`, `js`, or `font` and `image`, which are
 * only preloaded), and `mapped`, null for a URL linked as it is.
 *
 * @phpstan-type ReportedAsset array{path: string, public_path: string|null, type: string, source: ComponentMetadata::SOURCE_*, priority: int, attributes: array<string, mixed>, mapped: bool|null}
 * @phpstan-type ReportedRoute array{name: string, path: string}
 *
 * `ReportedStimulusController` is the `controller` variable of the template:
 * `name` as the bundle derives it (null outside `component_namespace`, for an
 * anonymous component, or when the component sets the variable itself,
 * `set_by_component`), whether the
 * template `used` it, and whether StimulusBundle has a controller of that name
 * (`registered`, null when that is not known).
 *
 * @phpstan-type ReportedStimulusController array{name: string|null, set_by_component: bool, used: bool, registered: bool|null}
 *
 * @internal
 */
final class ComponentReport
{
    /** The template found next to the class. */
    public const TEMPLATE_CO_LOCATED = 'co-located';
    /** The template named by the registration of the component (`template` argument or tag attribute). */
    public const TEMPLATE_EXPLICIT = 'explicit';
    /** The template returned by a method of the component (`template_from_method`), only known on render. */
    public const TEMPLATE_FROM_METHOD = 'method';
    /** The template Twig Components looks up when nothing else names one. */
    public const TEMPLATE_DEFAULT = 'default';
    /** No template: a service of the `ux.twig_component.twig_renderer` tag renders the component (`UX:Icon` of symfony/ux-icons). */
    public const TEMPLATE_RENDERER = 'renderer';
    /** The template of an anonymous component, which has no class. */
    public const TEMPLATE_ANONYMOUS = 'anonymous';

    /**
     * @param string|null                $class              null for an anonymous component
     * @param string                     $template           the Twig name; for TEMPLATE_FROM_METHOD, the method; for TEMPLATE_RENDERER, the class of the renderer
     * @param self::TEMPLATE_*           $templateOrigin
     * @param bool|null                  $templateFound      null when the template is only known on render, or when there is none (TEMPLATE_RENDERER)
     * @param list<ReportedAsset>        $assets
     * @param ReportedStimulusController $stimulusController
     * @param list<ReportedRoute>        $routes             routes of the controllers in the namespace of the component class
     * @param list<string>               $problems
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $class,
        public readonly string $template,
        public readonly string $templateOrigin,
        public readonly ?bool $templateFound,
        public readonly array $assets,
        public readonly array $stimulusController,
        public readonly array $routes,
        public readonly array $problems,
    ) {
    }

    /**
     * @return array<string, mixed> the JSON shape of `debug:sdc --format=json`
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'class' => $this->class,
            'template' => [
                'name' => $this->template,
                'origin' => $this->templateOrigin,
                'found' => $this->templateFound,
            ],
            'assets' => array_map(
                // An object, so that no attributes encode as {} like any others.
                static fn (array $asset): array => array_replace($asset, ['attributes' => (object) $asset['attributes']]),
                $this->assets,
            ),
            'stimulus_controller' => $this->stimulusController,
            'routes' => $this->routes,
            'problems' => $this->problems,
        ];
    }
}
