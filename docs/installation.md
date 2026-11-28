# Installation

## Requirements

- PHP 8.1 or later
- Symfony 6.4, 7.x or 8.x, with TwigBundle (installed with the bundle)
- `symfony/ux-twig-component` 2.20+ or 3.x
- AssetMapper — component assets are served through it (Webpack Encore is not supported)
- Optional: `symfony/stimulus-bundle` for component Stimulus controllers,
  `symfony/ux-live-component` for Live Components, `symfony/maker-bundle` for
  `make:sdc-component`

A Flex application created with `symfony new --webapp` has all of them except
`symfony/ux-twig-component`, which is installed with this bundle, as are
TwigBundle and AssetMapper.

## With the Flex recipe

```bash
composer require tito10047/ux-sdc
```

A Flex recipe for the bundle is proposed to
[symfony/recipes-contrib](https://github.com/symfony/recipes-contrib). Once it is
available — and with contrib recipes allowed
(`composer config extra.symfony.allow-contrib true`) — it:

- registers the bundle;
- creates `config/packages/ux_sdc.yaml` with `ux_components_dir` and
  `component_namespace` (`src_component/` and `App\Component`);
- creates `config/routes/ux_sdc.yaml`, the import of the routes of the component
  directory;
- creates the `src_component/` directory;
- adds `{{ render_component_assets() }}` after `{{ importmap('app') }}` in
  `templates/base.html.twig`, and removes it again with `composer remove` (see
  [Removing the bundle](#removing-the-bundle) for what it does not remove).

Flex also enables the bundle's loader controller in `assets/controllers.json`.
Two steps are left to you, and the recipe prints them:

1. Map the namespace to the directory in `composer.json`, then run
   `composer dump-autoload`:

   ```json
   {
       "autoload": {
           "psr-4": {
               "App\\": "src/",
               "App\\Component\\": "src_component/"
           }
       }
   }
   ```

2. Only for Live Components, Turbo Frames or Turbo Streams: attach the loader
   controller to `<body>`, see [The loader controller](live-and-turbo.md#the-loader-controller).

Then [create a component](components.md), or let
[`make:sdc-component`](maker.md) do it.

## Without the recipe

Without Flex, or before the recipe is available, do what it does by hand.

**1. Register the bundle** in `config/bundles.php` (Flex does it even without the
recipe):

```php
return [
    // ...
    Tito10047\UX\Sdc\SdcBundle::class => ['all' => true],
];
```

**2. Map a namespace to the component directory** in `composer.json` and run
`composer dump-autoload`, as above.

**3. Point the bundle at the same pair** in `config/packages/ux_sdc.yaml`:

```yaml
ux_sdc:
    ux_components_dir: '%kernel.project_dir%/src_component'
    component_namespace: 'App\Component'
```

The two must describe the same mapping: `App\Component\Alert\Alert` lives in
`src_component/Alert/Alert.php`. With `component_namespace` set, every class in
`ux_components_dir` is registered as an autowired and autoconfigured service,
so components need no entry in `services.yaml`.

The directory does not have to exist yet: `make:sdc-component` creates it with
the first component. Until then the bundle leaves it out of Twig and
AssetMapper, and a debug container picks it up as soon as it is created.

**4. Print the assets in the `<head>` of your base layout**, usually
`templates/base.html.twig`:

```twig
<head>
    {# ... #}
    {% block importmap %}{{ importmap('app') }}{% endblock %}
    {{ render_component_assets() }}
</head>
```

`render_component_assets()` prints a placeholder. When the response is sent, it is
replaced with the tags of the components rendered on the page. Put it **after
`{{ importmap() }}`**: component scripts are ES modules (see
[Scripts are ES modules](assets.md#scripts-are-es-modules)), and an import map has
to come before every module script of the page. In debug, the bundle logs a
warning when it finds the import map after the placeholder. Right after the
import map, as the recipe puts it, component styles also come after the
application's own. The recipe puts it inside `{% block javascripts %}`, next to
the import map; a template that overrides that block keeps it with
`{{ parent() }}`, as it has to for the import map anyway.

For Live Components and Turbo, also add the loader controller to `<body>` — see
[The loader controller](live-and-turbo.md#the-loader-controller).

**5. Import the routes of the component directory**, for `#[Route]` controllers
next to components, such as the pages `make:sdc-component --action` generates:

```yaml
# config/routes/ux_sdc.yaml
ux_sdc:
    resource: .
    type: ux_sdc
```

A bundle cannot add routes to an application by itself — the router loads only
what `config/routes*` imports — so this file is the one step that is about
routing. On Symfony 7.4 and later it is not needed when `config/routes.yaml` has
`resource: routing.controllers`, the default of applications created with 7.4+.
Having both does no harm: a route imported twice is registered once.

Nothing else is needed: the bundle adds `ux_components_dir` to Twig's paths,
AssetMapper's paths (with its `*.twig` files excluded, so templates are not
published to `public/assets/`) and StimulusBundle's controller paths, next to the
application's own. See the [configuration reference](configuration.md) for the
options.

## Removing the bundle

`composer remove tito10047/ux-sdc` undoes what the recipe did, not what you added
by hand. Once the bundle is gone, Twig no longer knows its functions and
variables, and every page that still uses one fails to render. The usual one is
the loader controller on `<body>`, which the recipe never adds:

```text
Variable "sdc_loader_controller" does not exist.
```

Before removing the bundle, take out of your templates:

- `{{ stimulus_controller(sdc_loader_controller) }}` on `<body>`;
- `{{ render_component_assets() }}`, if you added it yourself rather than the
  recipe (`Unknown "render_component_assets" function`);
- the components in `ux_components_dir`, or move them to plain Twig components:
  the bundle registers their classes as services, and sets the `controller`
  variable their templates pass to `stimulus_controller()`.

Then delete `config/packages/ux_sdc.yaml` and `config/routes/ux_sdc.yaml` if you
created them yourself, and the `@tito10047/ux-sdc` entry of
`assets/controllers.json` if Flex did not remove it.
