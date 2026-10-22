# Configuration

## Reference

The `ux_sdc` options with their defaults, as
`php bin/console config:dump-reference ux_sdc` prints them. Copied as is, this
registers no components: `component_namespace` has to be set, as in
[Installation](installation.md).

```yaml
ux_sdc:

    # Root directory of the components: each component class sits in it next to its template, CSS and JS. Registered as a Twig path and as an AssetMapper path.
    ux_components_dir:    '%kernel.project_dir%/src_component'

    # PHP namespace mapped to ux_components_dir (PSR-4), e.g. "App\Component". When set, the classes in that directory are registered as autowired and autoconfigured services, and Stimulus controller names are derived relative to it. Null registers nothing: the application registers its components itself.
    component_namespace:  null

    # Integration with StimulusBundle. Accepts false as a shorthand for "enabled: false".
    stimulus:

        # Add ux_components_dir to the Stimulus controller paths, next to those of the application (assets/controllers by default), so that a *_controller.js next to a component is registered as a controller.
        enabled:              true

    # Routes of the #[Route] controllers in ux_components_dir, such as those make:sdc-component --action generates. Accepts false as a shorthand for "enabled: false". Takes effect only with component_namespace set.
    routing:

        # Load those routes through the "ux_sdc" route import (resource: ., type: ux_sdc) and, on Symfony 7.4+, through "resource: routing.controllers". Off: the import loads nothing and the classes are left out of routing.controllers; an import of the directory the application writes itself still works.
        enabled:              true

    # Load the CSS, JS and Twig template named after a component class and sitting next to it (Alert.php: Alert.css, Alert.js, Alert.html.twig) without declaring them. Assets declared with #[AsSdcComponent] or #[Asset] are loaded either way.
    auto_discovery:       true

    # How component metadata (assets and template) is found. true: once, when the container is built, into %kernel.cache_dir%/sdc_metadata.php; asset files added or removed later need a cache:clear. false: on render, from the files next to the component class, so new files are picked up without clearing the cache. auto: false when kernel.debug is on, true otherwise.
    metadata_cache:       auto # One of "auto"; true; false

    # Keep the Twig templates (*.twig) of ux_components_dir out of the AssetMapper, so that asset-map:compile does not publish them to public/assets/. The directory itself stays mapped for the component CSS and JS.
    exclude_templates_from_asset_map: true

    # The marker that render_component_assets() prints and that is replaced, in an HTML response, with the <link> and <script> tags of the components rendered on the page. Choose a string that cannot occur in the page otherwise.
    placeholder:          '<!-- __UX_TWIG_COMPONENT_ASSETS__ -->'

    # Service ID of a Tito10047\UX\Sdc\Csp\NonceProviderInterface supplying the Content Security Policy nonces of the current request, put on the injected <script> and <link> tags. "Tito10047\UX\Sdc\Csp\NelmioNonceProvider" uses those of NelmioSecurityBundle (its "csp" feature has to be on). Null injects the tags without a nonce.
    csp_nonce_provider:   null
```

A minimal configuration, the one the Flex recipe creates:

```yaml
# config/packages/ux_sdc.yaml
ux_sdc:
    ux_components_dir: '%kernel.project_dir%/src_component'
    component_namespace: 'App\Component'
```

`ux_components_dir` and `component_namespace` must describe the same Composer
PSR-4 mapping (see [Installation](installation.md#without-the-recipe)).

## `metadata_cache`

`metadata_cache` follows the debug flag, not the environment name: a `staging`
environment running with `APP_DEBUG=1` resolves metadata on render, and `prod` or
`test` without debug use the cache. The choice is made when the container is built,
so the option cannot come from an environment variable. With `true`, the cache is
rebuilt with the container: adding or removing an asset file next to a component
then needs a `cache:clear` (editing an existing file does not). With `false`, a
long-running worker (FrankenPHP, RoadRunner) remembers what it resolved for a
component until the worker restarts.

Both settings render the same pages; see [How it works](how-it-works.md) for what
each does and [Performance](how-it-works.md#performance) for what it costs.

## Parameters

| Parameter | Value |
|-----------|-------|
| `ux_sdc.ux_components_dir` | the configured `ux_components_dir`, for configuration of your own that points at the same directory |
| `app.ui_components.dir` | the same; **deprecated** since 1.0 and removed in 2.0 — `app.*` parameters belong to the application. Use `ux_sdc.ux_components_dir`. |

Every other parameter the bundle sets is internal.
