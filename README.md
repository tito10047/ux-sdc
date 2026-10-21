# UX SDC Bundle

[![CI](https://img.shields.io/github/actions/workflow/status/tito10047/ux-sdc/ci.yml?branch=main&label=CI)](https://github.com/tito10047/ux-sdc/actions/workflows/ci.yml)
[![Latest Stable Version](https://img.shields.io/packagist/v/tito10047/ux-sdc.svg)](https://packagist.org/packages/tito10047/ux-sdc)
[![PHP Version](https://img.shields.io/packagist/php-v/tito10047/ux-sdc.svg)](https://packagist.org/packages/tito10047/ux-sdc)
[![Symfony Version](https://img.shields.io/badge/Symfony-6.4%20%7C%207%20%7C%208-black?logo=symfony)](https://symfony.com/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)

**Single Directory Components** for Symfony UX: a component's PHP class, Twig
template, CSS and JavaScript live in one directory, and the CSS and JS reach the
page only when the component is rendered on it.

```text
src_component/
└── Alert/
    ├── Alert.php            # the component class
    ├── Alert.html.twig      # its template, found without configuration
    ├── Alert.css            # added to <head> when an Alert is rendered
    └── Alert_controller.js  # its Stimulus controller (optional)
```

The bundle connects [Twig Components](https://symfony.com/bundles/ux-twig-component/current/index.html)
with [AssetMapper](https://symfony.com/doc/current/frontend/asset_mapper.html): it
registers the component directory with Twig, AssetMapper and StimulusBundle, finds
the files next to each component class, and injects the `<link>` and `<script>`
tags of the components a response actually rendered.

The idea comes from Hugo Alliaume's article
[*A Better Architecture for Your Symfony UX Twig Components*](https://hugo.alliau.me/blog/posts/a-better-architecture-for-your-symfony-ux-twig-components).
It is used in production on [mostka.sk](https://mostka.sk/),
[formalitka.mostka.sk](https://formalitka.mostka.sk/) and [ycon.cc](https://ycon.cc).

## Requirements

- PHP 8.1 or later
- Symfony 6.4, 7.x or 8.x, with TwigBundle
- `symfony/ux-twig-component` 2.20+ or 3.x
- AssetMapper — component assets are served through it (Webpack Encore is not supported)
- Optional: `symfony/stimulus-bundle` for component Stimulus controllers,
  `symfony/ux-live-component` for Live Components, `symfony/maker-bundle` for
  `make:sdc-component`

A Flex application created with `symfony new --webapp` has all of them except
`symfony/ux-twig-component`, which is installed with this bundle.

## Installation

**1. Install the bundle.**

```bash
composer require tito10047/ux-sdc
```

Symfony Flex registers the bundle. Without Flex, add it to `config/bundles.php`:

```php
return [
    // ...
    Tito10047\UX\Sdc\SdcBundle::class => ['all' => true],
];
```

**2. Create the component directory and map a namespace to it** in
`composer.json`, then run `composer dump-autoload`:

```bash
mkdir src_component
```

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
replaced with the tags of the components rendered on the page. Put it last in
`<head>`, so component styles come after the application's own.

For Live Components, also add the loader controller to `<body>` — see
[Live Components](#live-components).

Nothing else is needed: the bundle adds `ux_components_dir` to Twig's paths,
AssetMapper's paths (with its `*.twig` files excluded, so templates are not
published to `public/assets/`) and StimulusBundle's controller paths, next to the
application's own.

## Your first component

`src_component/Alert/Alert.php`:

```php
<?php

namespace App\Component\Alert;

use Tito10047\UX\Sdc\Attribute\AsSdcComponent;

#[AsSdcComponent]
final class Alert
{
    public string $type = 'info';
    public string $message;
}
```

`src_component/Alert/Alert.html.twig`:

```twig
<div {{ attributes.defaults({class: 'alert alert-' ~ type}) }}>
    {{ message }}
</div>
```

`src_component/Alert/Alert.css`:

```css
.alert { padding: 1rem; border-radius: .25rem; }
.alert-success { background: #e6f4ea; }
```

Render it from any template:

```twig
<twig:Alert:Alert type="success" message="Saved." />
```

The component name is the class name relative to `component_namespace`, with
`\` as `:` — `App\Component\Alert\Alert` is `Alert:Alert`, and
`App\Component\UI\Button\Button` is `UI:Button:Button`. Pass a name to the
attribute to choose another one: `#[AsSdcComponent('Alert')]`.

On a page that renders an Alert, the `<head>` gets
`<link rel="stylesheet" href="/assets/Alert/Alert-….css">` and the response a
`Link: </assets/Alert/Alert-….css>; rel="preload"; as="style"` header. A page
without an Alert gets neither.

## Components

### Co-located files

For a class `Alert`, the bundle looks next to `Alert.php` for:

| File | Used as |
|------|---------|
| `Alert.html.twig` | the component template |
| `Alert.css` | a stylesheet, added to `<head>` when the component renders |
| `Alert.js` | a classic `<script>`, added to `<head>` when the component renders |
| `Alert_controller.js` | a Stimulus controller, loaded by StimulusBundle (see below) |

The file names follow the class name exactly, case included. Rename the class,
rename the files. Discovery can be turned off with `auto_discovery: false`; then
templates and assets have to be declared explicitly.

A template named explicitly always wins over the one next to the class:

```php
#[AsSdcComponent(template: 'components/alert.html.twig')] // Alert.html.twig next to the class is ignored
final class Alert {}
```

The same goes for `#[AsTwigComponent]`, `#[AsLiveComponent]` and a `twig.component`
service tag with a `template`. The component's CSS and JS are still discovered.

### Attributes

**`#[AsSdcComponent]`** is `#[AsTwigComponent]` with two extra arguments, for a
stylesheet and a script that do not follow the naming convention. They are loaded
in addition to the discovered files:

```php
#[AsSdcComponent(css: 'Alert/alert.theme.css', js: 'Alert/alert.legacy.js')]
final class Alert {}
```

Discovery works for `#[AsTwigComponent]` and `#[AsLiveComponent]` classes too;
`#[AsSdcComponent]` is only needed for `css` and `js`. Use one component
attribute per class: `#[AsLiveComponent]` combined with `#[AsSdcComponent]`
fails the container build with *"another component already has this name"*.

**`#[Asset]`** adds any other stylesheet or script, from any AssetMapper path or
an absolute URL. It is repeatable and works on every component class:

```php
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Tito10047\UX\Sdc\Attribute\Asset;

#[AsTwigComponent]
#[Asset('styles/reset.css', priority: 10)]
#[Asset('https://cdn.example.com/chart.js', type: 'js', attributes: ['defer' => true])]
final class Chart {}
```

| Argument | Meaning |
|----------|---------|
| `path` | AssetMapper logical path (`styles/reset.css`) or a URL. Required — `#[Asset]` without a path is ignored. |
| `type` | `css` or `js`. When omitted, a path ending in `.css` is a stylesheet and **anything else is loaded as a script**, so do not declare fonts or images this way. |
| `priority` | Higher comes first in `<head>`. Default `0`. |
| `attributes` | Extra HTML attributes for the tag. `true` renders a bare attribute, `false` and `null` leave it out. |

`#[Asset]` is for files that are *not* next to the class. It does not switch
discovery on or off; `auto_discovery` does.

### Stimulus controllers

Every component in `component_namespace` gets a `controller` template variable:
the name StimulusBundle registers for the `_controller.js` file next to its
class. `Alert_controller.js`:

```js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    close() {
        this.element.remove();
    }
}
```

`Alert.html.twig`:

```twig
<div {{ attributes.defaults({class: 'alert alert-' ~ type}).defaults(stimulus_controller(controller)) }}>
    {{ message }}
    <button type="button" {{ stimulus_action(controller, 'close') }}>&times;</button>
</div>
```

The name is the class path relative to `component_namespace`, with `\` as `--`
and `_` as `-`, case kept: `Alert/Alert.php` → `Alert--Alert`,
`UI/Date_Picker/Date_Picker.php` → `UI--Date-Picker--Date-Picker`.

- No trait or interface is needed. A `controller` the component exposes itself
  (a public property, `#[ExposeInTemplate]`) is left alone.
- Without `component_namespace`, or for a class outside it, the variable is not set.
- The `Tito10047\UX\Sdc\Twig\Stimulus` trait, together with
  `Tito10047\UX\Sdc\Twig\ComponentNamespaceInterface`, is only needed for the
  name in PHP (`$this->getController()`).
- `Alert.js` and `Alert_controller.js` are different things: the first is a
  `<script>` tag added with the component, the second a controller loaded by
  StimulusBundle. Do not write both for the same behaviour.

`stimulus.enabled: false` stops the bundle from adding `ux_components_dir` to the
Stimulus controller paths.

### Where components can live

Co-located files are loaded by the name they have where they are served from:

* the template by its **Twig name**, relative to the Twig path it sits in;
* the stylesheet and script by their **AssetMapper logical path**, relative to the
  AssetMapper path they sit in.

`ux_components_dir` is set up for this out of the box: the bundle registers it both
as a Twig path and as an AssetMapper path, so `src_component/Alert/Alert.css` is
`Alert/Alert.css` and `src_component/Alert/Alert.html.twig` is
`Alert/Alert.html.twig`. This is where single-directory components belong.

Every Twig component is processed, wherever its class lives, but a co-located file
without such a name is **not loaded**. A class in `src/Twig/Components/` with
`Alert.css` next to it therefore gets no stylesheet. Either move the component into
`ux_components_dir`, or reference the file explicitly by its AssetMapper logical
path:

```php
#[AsTwigComponent]
#[Asset('styles/alert.css')] // a file in assets/styles/, or any other AssetMapper path
final class Alert {}
```

A directory of your own works too, as long as it is both kinds of path. The names
follow each path's namespace, and the two namespaces are independent:

```yaml
# config/packages/twig.yaml
twig:
    paths:
        '%kernel.project_dir%/lib/ui': ui          # lib/ui/Badge/Badge.html.twig → @ui/Badge/Badge.html.twig

# config/packages/asset_mapper.yaml
framework:
    asset_mapper:
        paths:
            lib/ui: ui                              # lib/ui/Badge/Badge.css → ui/Badge/Badge.css
        # Keep the templates out of public/assets/; the bundle does this for ux_components_dir only.
        excluded_patterns:
            - '%kernel.project_dir%/lib/ui/*.twig'
```

(The classes in it have to be registered as services, like any other code outside
`ux_components_dir`.) A directory that is only a Twig path — `templates/`, for
example — gets its templates found, but its stylesheets and scripts cannot be
served and are left out.

## Live Components

Live Components get their assets like any other component on the first render.
When a re-render brings a component that is not on the page yet, the response
cannot carry new `<link>` tags; the bundle sends the asset URLs in the
`X-SDC-Assets-CSS` and `X-SDC-Assets-JS` headers instead, and a Stimulus
controller shipped with the bundle adds the missing ones to `<head>`.

Attach the controller once, to `<body>`:

```twig
<body {{ stimulus_controller(sdc_loader_controller) }}>
```

Put it on `<body>` rather than an inner element: it has to connect before the
Live Components it watches.

Symfony Flex enables the controller in `assets/controllers.json` when the bundle
is installed. Without Flex, add it yourself:

```json
{
    "controllers": {
        "@tito10047/ux-sdc": {
            "sdc_loader": {
                "enabled": true,
                "fetch": "eager"
            }
        }
    },
    "entrypoints": []
}
```

A Live Component in `ux_components_dir` needs nothing beyond `#[AsLiveComponent]`.
`src_component/Search/Search.php`:

```php
<?php

namespace App\Component\Search;

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent] // Search.html.twig, Search.css and Search.js next to it are discovered
final class Search
{
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public string $query = '';
}
```

`src_component/Search/Search.html.twig`:

```twig
<div {{ attributes.defaults(stimulus_controller(controller)) }}>
    <input type="search" data-model="query" value="{{ query }}">
    <p>Results for "{{ query }}"</p>
</div>
```

`controller` is set on every re-render too; on the root element it is added next to
the `live` controller (`data-controller="Search--Search live"`).

## Generating components

With `symfony/maker-bundle` installed:

```bash
php bin/console make:sdc-component UI:Alert
```

creates, in `ux_components_dir`:

```text
src_component/UI/Alert/
├── Alert.php            # #[AsSdcComponent] class App\Component\UI\Alert\Alert
├── Alert.html.twig
├── Alert.css
└── Alert_controller.js  # only with --stimulus, or when confirmed interactively
```

| Option | Effect |
|--------|--------|
| `name` | Component name; `:` or `/` separate directories (`UI:Alert`, `UI/Alert`). Asked for when omitted. |
| `--stimulus` | Also generate `<Name>_controller.js`. Without the option, asked interactively (default yes); never generated with `--no-interaction`. |
| `--action` | Also generate a page controller and its template (see below). Without the option, asked interactively (default no). |
| `--route-path=PATH` | Path of the page's route, instead of the one derived from the name. Implies `--action`. |
| `--layout=TEMPLATE` | Template the page extends, instead of the one detected. Implies `--action`. |

The maker needs `component_namespace`, and writes the PHP class where Composer's
PSR-4 mapping puts it and the other files into `ux_components_dir`. When the two
do not point to the same directory — or `component_namespace` is not set, or
Composer does not autoload it — it stops before writing anything and says which
setting to fix, rather than leave the class apart from its template and assets.

### Page components with `--action`

```bash
php bin/console make:sdc-component Page:Homepage --action
```

additionally creates a controller and a page template next to the component:

```php
// src_component/Page/Homepage/HomepageAction.php
namespace App\Component\Page\Homepage;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomepageAction extends AbstractController
{
    #[Route('/homepage', name: 'app_homepage')]
    public function __invoke(): Response
    {
        return $this->render('Page/Homepage/HomepageAction.html.twig');
    }
}
```

```twig
{# src_component/Page/Homepage/HomepageAction.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <twig:Page:Homepage:Homepage />
{% endblock %}
```

- **Route.** The path is the component name, each part kebab-cased, without a
  leading `Page` — the namespace of page components, see
  [Organizing components](#organizing-components): `Page:Homepage` → `/homepage`,
  `Page:Blog:BlogPost` → `/blog/blog-post`, `Admin:Dashboard` → `/admin/dashboard`.
  The route name is the one `make:controller` would give the same name:
  `app_homepage`, `app_blog_blog_post`, `app_admin_dashboard`. Choose another path
  with `--route-path`; the name is yours to edit.
- **Layout.** The page extends `layout.html.twig` if Twig finds one, else
  `base.html.twig`, and fills the `content` block if that template has one —
  defined in it or in a template it extends — else `body`. On a Flex application
  that is `base.html.twig` and `body`. Choose another template with `--layout`.
  When there is no template to extend, or it has neither block, the maker stops
  and says so.
- **The route has to be loaded.** The bundle does not load routes itself; the
  maker prints what your application needs. On Symfony 7.4 and later, a
  `config/routes.yaml` with the `routing.controllers` resource — the default of
  new 7.4+ applications — loads it with no further configuration, because the
  bundle registers the controller as an autoconfigured service:

  ```yaml
  controllers:
      resource: routing.controllers
  ```

  On Symfony 6.4 to 7.3, or with a `routes.yaml` that imports `../src/Controller/`
  by path, import the component directory as well, or the page returns 404:

  ```yaml
  # config/routes.yaml
  components:
      resource:
          path: ../src_component/
          namespace: App\Component
      type: attribute
  ```

  Check with `php bin/console debug:router app_homepage`.

### Organizing components

One layout that works well, and keeps a page's controller, template and styles
in one place:

```text
src_component/
├── UI/          # generic elements: Button, Spinner, Tabs
├── Layout/      # page frame: TopBar, Footer, FlashMessage
├── Component/   # reusable feature blocks
└── Page/        # one directory per page: Homepage/Homepage.php + HomepageAction.php
```

## Configuration reference

All keys with their defaults (`php bin/console config:dump-reference ux_sdc`
prints the same with longer descriptions). Copied as is, it registers no
components: `component_namespace` has to be set, as in [Installation](#installation).

```yaml
ux_sdc:
    # Root directory of the components. Registered as a Twig path, an AssetMapper
    # path and (see stimulus.enabled) a Stimulus controller path.
    ux_components_dir: '%kernel.project_dir%/src_component'

    # PHP namespace mapped to ux_components_dir by Composer (PSR-4). When set, the
    # classes in that directory are registered as autowired, autoconfigured
    # services, and Stimulus controller names are derived relative to it.
    # null registers nothing: the application registers its components itself.
    component_namespace: null # e.g. 'App\Component'

    stimulus:
        # Add ux_components_dir to the Stimulus controller paths, next to those of
        # the application. "stimulus: false" is a shorthand for "enabled: false".
        enabled: true

    # Find Alert.html.twig, Alert.css and Alert.js next to Alert.php. Assets
    # declared with #[AsSdcComponent] or #[Asset] are loaded either way.
    auto_discovery: true

    # Where component metadata (asset paths and template) comes from:
    #   true  - resolved once, when the container is built, and cached in
    #           %kernel.cache_dir%/sdc_metadata.php. No filesystem lookups on render.
    #   false - resolved on render from the files next to each component class.
    #   auto  - false when kernel.debug is on, true otherwise.
    metadata_cache: auto

    # Keep the *.twig files of ux_components_dir out of the AssetMapper, so that
    # asset-map:compile does not publish them to public/assets/.
    exclude_templates_from_asset_map: true

    # What render_component_assets() prints and the response listener replaces.
    placeholder: '<!-- __UX_TWIG_COMPONENT_ASSETS__ -->'
```

`metadata_cache` follows the debug flag, not the environment name: a `staging`
environment running with `APP_DEBUG=1` resolves metadata on render, and `prod` or
`test` without debug use the cache. The choice is made when the container is built,
so the option cannot come from an environment variable. With `true`, the cache is
rebuilt with the container: adding or removing an asset file next to a component
then needs a `cache:clear` (editing an existing file does not). With `false`, a
long-running worker (FrankenPHP, RoadRunner) remembers what it resolved for a
component until the worker restarts.

## How it works

1. **Container build.** The bundle registers `ux_components_dir` as a Twig path,
   an AssetMapper path (minus its templates) and a Stimulus controller path, and
   adds a `twig_component.defaults` entry for `component_namespace`. Every class under `component_namespace` is registered
   as an autoconfigured service, so `#[AsSdcComponent]`, `#[AsTwigComponent]`,
   `#[AsLiveComponent]` and `#[Route]` on it take effect. A compiler pass collects
   the application's Twig and AssetMapper paths; with `metadata_cache` on, it
   resolves the template and assets of every `twig.component` service and writes
   them to `%kernel.cache_dir%/sdc_metadata.php`.
2. **Rendering.** On TwigComponent's `PostMountEvent` and `PreRenderEvent`, a
   listener adds the component's assets to a per-request registry, points the
   component at its co-located template unless one was named explicitly, and sets
   the `controller` variable. With `metadata_cache` on it reads the cached file;
   off, it resolves the files next to the class (once per class and process).
3. **Response.** A `kernel.response` listener (priority 64, ahead of WebLink's
   `Link` header listener) replaces the first placeholder in an HTML main response
   with the tags, sorted by priority, and adds a `Link: rel="preload"` header for
   each. For a Live Component re-render it sets the `X-SDC-Assets-*` headers
   instead. The registry is reset between requests, so long-running workers do not
   carry assets over.

Components rendered in a sub-request (`render(controller(...))`) end up in the
main response.

## Diagnostics

Components degrade gracefully: a missing asset never breaks a page. The bundle logs
why instead, through the application's `logger` (on the `ux_sdc` channel with
MonologBundle):

| Level | When | Logged |
|-------|------|--------|
| warning | A co-located template is outside every Twig path, or a co-located CSS/JS file outside every AssetMapper path, so it is left out (see [Where components can live](#where-components-can-live)). | in debug only; at build time (`metadata_cache: true`) into the container compiler log, shown in the profiler's *Logs* panel under *Container* |
| warning | An HTML page with a `<head>` renders components with assets but does not call `render_component_assets()`. Fragments without a `<head>` (Turbo Frames, partials) are not reported. | in debug only |
| error | `metadata_cache` is on but `%kernel.cache_dir%/sdc_metadata.php` does not exist — the cache was not warmed where the application runs, or the runtime cache directory differs from the build one. Pages still render, without component CSS and JS. | always |

Every message says what to change.

## Known limitations

- **Anonymous components** (a template without a PHP class) get no co-located
  assets; only class-based components do.
- **Turbo Frames and Streams.** Turbo takes only the frame or stream from the
  response and ignores its `<head>`, and only Live Component requests get the
  `X-SDC-Assets-*` headers. The CSS and JS of a component that first appears in a
  frame or stream are therefore not loaded unless the page already has them.
- **Assets added by a Live re-render** are inserted as plain `<link>` and
  `<script>` tags: the `attributes` of `#[Asset]` (`type="module"`, `nonce`, …) do
  not travel with the headers.
- **Streamed responses.** Components rendered while a `StreamedResponse` is being
  sent are registered after `kernel.response` and get no assets.
- **ESI.** Each ESI fragment is its own main request; assets of its components do
  not reach the page that includes it.
- **New asset files without a rebuild.** With `metadata_cache: true`, adding or
  removing a CSS/JS/Twig file next to a component needs a `cache:clear`. With
  `false`, a long-running worker picks it up after a restart.

## Non-goals

- **SCSS, TypeScript or any other compilation.** Compiling assets is AssetMapper's
  job (or that of a dedicated bundle such as `symfonycasts/sass-bundle`). Compile
  `Alert.scss` to `Alert.css` next to the class and discovery picks it up.
- **Bundling or importmap manipulation.** The bundle is a thin layer between
  TwigComponent and AssetMapper and does not build its own asset pipeline.
- **Webpack Encore.** Component assets are served through AssetMapper only.

## Performance

Measured with 500 components (details and methodology in [benchmark.md](benchmark.md)):

| Scenario | Classic Twig components | SDC | Difference |
|----------|-------------------------|-----|------------|
| Container warmup, debug | 809.8 ms | 782.0 ms | −27.8 ms |
| Container warmup, no debug | 583.1 ms | 586.2 ms | +3.1 ms |
| Render, `metadata_cache: true` | 26.5 ms | 31.6 ms | +5.1 ms |
| Render, `metadata_cache: false`, 500 unique | 26.5 ms | 88.4 ms | +61.9 ms |
| Render, `metadata_cache: false`, 10 unique repeated | 26.5 ms | 58.0 ms | +31.5 ms |

With the metadata cache, which production uses by default, the overhead is about
10 µs per component. Without it (the default in debug), each component class costs
a filesystem lookup the first time it renders in a process. Building the cache adds
about 8 MB of memory to container compilation for 500 components.

## AI agent skill

[tito10047/ux-sdc-skills](https://github.com/tito10047/ux-sdc-skills) teaches
coding agents how SDC components are discovered, how their assets reach the
browser, and how to diagnose the failure modes above.

```
/plugin marketplace add tito10047/ux-sdc-skills
/plugin install ux-sdc-skills
```

For agents other than Claude Code, the skill is a plain directory of Markdown
following the [agentskills.io](https://agentskills.io) layout; `AGENTS.md`,
`CLAUDE.md` and `llms.txt` are provided as well.

## Contributing

Bug reports and pull requests are welcome on
[GitHub](https://github.com/tito10047/ux-sdc/issues). See
[CHANGELOG.md](CHANGELOG.md) for what changed between versions.

## License

MIT — see [LICENSE.md](LICENSE.md).
