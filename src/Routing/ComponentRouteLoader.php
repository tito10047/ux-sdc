<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Routing;

use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\Routing\RouteCollection;

/**
 * Loads the #[Route] attributes of the classes in ux_components_dir, such as
 * the controllers `make:sdc-component --action` generates, for the import
 *
 *     # config/routes/ux_sdc.yaml
 *     ux_sdc:
 *         resource: .
 *         type: ux_sdc
 *
 * A bundle cannot add routes without an import in the application: the router
 * loads only what config/routes* names. Symfony 7.4 added one way around it,
 * `resource: routing.controllers`, which loads every service tagged
 * "routing.controller" — and the component classes are autoconfigured services,
 * so that import already covers them. This one is for the applications whose
 * routes.yaml imports controllers by path, every one before Symfony 7.4.
 *
 * The directory is imported the way `resource: {path, namespace}, type: attribute`
 * imports it, so a route loaded by both (or by `routing.controllers` too) gets
 * the same name each time and the router keeps one of them.
 *
 * @internal The `type: ux_sdc` route import is the public contract, not this class.
 */
final class ComponentRouteLoader extends Loader
{
    public const TYPE = 'ux_sdc';

    /**
     * @param string|null $directory ux_components_dir, absolute; null when the bundle contributes no routes
     * @param string|null $namespace the PSR-4 namespace of that directory; null when the bundle contributes no routes
     */
    public function __construct(
        private readonly ?string $directory,
        private readonly ?string $namespace,
    ) {
        parent::__construct();
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        if (null === $this->directory || null === $this->namespace) {
            return new RouteCollection();
        }

        // Before the first component is made the directory does not exist, and
        // the attribute loader would throw on it, taking every route of the
        // application down. The router cache is refreshed once it is created.
        if (!is_dir($this->directory)) {
            $collection = new RouteCollection();
            $collection->addResource(new FileExistenceResource($this->directory));

            return $collection;
        }

        $collection = $this->import(['path' => $this->directory, 'namespace' => $this->namespace], 'attribute');
        if (!$collection instanceof RouteCollection) {
            throw new \LogicException(\sprintf('The attribute route loader returned %s for "%s" instead of a route collection.', get_debug_type($collection), $this->directory));
        }

        return $collection;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return self::TYPE === $type;
    }
}
