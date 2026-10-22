# Upgrading

## From 0.4 to 1.0

Most applications need four things:

1. Require `tito10047/ux-sdc:^1.0`.
2. Put `{{ render_component_assets() }}` after `{{ importmap() }}` in the layout,
   and check that component scripts work as ES modules
   ([Component scripts are ES modules](#component-scripts-are-es-modules)).
3. Declare a `type` for every `#[Asset]` whose path has no recognised extension
   ([`#[Asset]` types are validated](#asset-types-are-validated)).
4. If you copied `sdc_loader_controller.js` or read the `X-SDC-Assets-CSS`/`-JS`
   headers yourself, switch to the `X-SDC-Assets` header
   ([One `X-SDC-Assets` header](#one-x-sdc-assets-header-replaces-x-sdc-assets-css-and-x-sdc-assets-js)).

The rest of this page lists every change that can affect an application, grouped by
area. Each entry says what changed and what to do, if anything.

### Requirements

#### `symfony/ux-twig-component` 2.20 or later

- **Before:** `^2.13|^3.0`.
- **After:** `^2.20|^3.0`. On 2.13–2.19 every component rendered without the
  metadata cache failed with *Call to undefined method
  ComponentMetadata::isAnonymous()*, so the old constraint was never accurate.
- **What to do:** nothing, unless you pin Twig Components below 2.20; then update it.

#### TwigBundle, `psr/log`, `symfony/filesystem` and `symfony/service-contracts` are dependencies

- **Before:** installed only through other packages.
- **After:** required by the bundle. PHP 8.1 and Symfony 6.4, 7.x and 8.x are
  supported as before.
- **What to do:** nothing.

### Configuration

#### The metadata pipeline follows the debug flag, not the environment name

- **Before:** an environment named exactly `dev` resolved component metadata on
  render; every other one used the build-time cache.
- **After:** the new option `metadata_cache` (`auto`, `true` or `false`) decides.
  `auto`, the default, resolves on render when `kernel.debug` is on and uses the
  cache otherwise. So `staging` with `APP_DEBUG=1` resolves on render, `dev` with
  debug off uses the cache, and `test` with debug on (Symfony's default) resolves on
  render. Pages render the same either way. The option cannot come from an
  environment variable.
- **What to do:** nothing for the rendered pages. A test that reads
  `SdcMetadataRegistry` finds it empty without the cache; set
  `ux_sdc.metadata_cache: true` for that test kernel, or better, assert on the
  response or on `debug:sdc --format=json` (the registry is internal now, see
  [Internal classes](#classes-not-meant-for-applications-are-internal)).

#### `app.ui_components.dir` is deprecated

- **Before:** the bundle set the parameter `app.ui_components.dir`, a copy of
  `ux_sdc.ux_components_dir`.
- **After:** it is still set, but deprecated; reading it triggers a deprecation.
  It will be removed in 2.0: `app.*` parameters belong to the application.
- **What to do:** replace `%app.ui_components.dir%` with
  `%ux_sdc.ux_components_dir%`.

#### `stimulus: false` disables the Stimulus integration

- **Before:** the shorthand was accepted but ignored; only
  `stimulus: { enabled: false }` worked.
- **After:** both disable it.
- **What to do:** if you wrote `stimulus: false` and still rely on the component
  controllers, remove it.

#### `placeholder` cannot be empty

- **Before:** an empty `placeholder` was accepted and matched every response.
- **After:** the configuration is rejected.
- **What to do:** set a non-empty marker, or leave the default.

#### Workarounds that are no longer needed

The bundle now does these itself; the workarounds still work, but can be removed:

- `framework.asset_mapper.excluded_patterns` for the `*.twig` files of
  `ux_components_dir` — the bundle excludes them
  (`exclude_templates_from_asset_map`, default `true`), so `asset-map:compile` no
  longer publishes component templates to `public/assets/`.
- A `config/packages/stimulus.yaml` written only to keep `assets/controllers/`
  registered. The bundle used to prepend its directory to
  `stimulus.controller_paths`, which replaced StimulusBundle's default; it now adds
  the directory next to the application's paths.
- An attribute route import of `src_component/` on Symfony 6.4–7.3. The new
  import `type: ux_sdc` (`config/routes/ux_sdc.yaml`, see
  [Installation](docs/installation.md#without-the-recipe)) does the same on every
  version; a route imported twice is registered once, so keeping both is harmless.
- Creating `ux_components_dir` before installing the bundle. A missing directory no
  longer breaks Twig and `debug:asset-map`.

New options, all with defaults that need no change: `metadata_cache`,
`exclude_templates_from_asset_map`, `routing.enabled` and `csp_nonce_provider`
(see [Configuration](docs/configuration.md)).

### Templates and assets

#### Component scripts are ES modules

- **Before:** `<script src="…">`, preloaded with `Link: …; rel="preload"; as="script"`.
- **After:** `<script src="…" type="module">` for every component script —
  discovered, `#[AsSdcComponent(js: …)]` and `#[Asset]` alike — without a `Link`
  preload. A module runs after the page is parsed, in strict mode and in a scope of
  its own: its top-level `var` and `function` are not globals, and `document.write`
  does not work.
- **What to do:**
  - put `{{ render_component_assets() }}` **after** `{{ importmap() }}`: a browser
    that does not merge import maps ignores one that comes after a module script,
    and every bare import of the page then fails. In debug, the bundle logs a
    warning when it finds the import map after the placeholder;
  - opt a script that is not a module (a UMD build, a script relying on globals)
    out with `#[Asset('…', attributes: ['type' => false])]`. Do not use `nomodule`.
- **Also:** the import map of every page lists the module script of every
  component under its unversioned URL (`"/assets/Card/Card.js": "/assets/Card/Card-3b1f2a4.js"`),
  and AssetMapper adds the files they import, so `import './format.js'` in a
  component script resolves. `importmap.php` is not changed. A workaround that
  listed component scripts in `importmap.php` for that can go; one under the same
  name wins. An application that replaced the `asset_mapper.importmap.config_reader`
  service with a class of its own does not get the entries (noted in the container
  compiler log) — see [Imports in a component script](docs/assets.md#imports-in-a-component-script).

#### `#[Asset]` types are validated

- **Before:** an `#[Asset]` without `type` was a stylesheet if its path ended in
  `.css` and a script otherwise — `#[Asset('fonts/inter.woff2')]` became
  `<script src="…woff2">`. A `type` other than `css` and `js` was accepted and the
  asset silently never loaded.
- **After:** the type is `css`, `js`, `font` or `image` (`Asset::CSS`, `Asset::JS`,
  `Asset::FONT`, `Asset::IMAGE`). Without `type`, the extension decides: `.css`;
  `.js`, `.mjs`; `.woff2`, `.woff`, `.ttf`, `.otf`; `.avif`, `.webp`, `.png`,
  `.jpg`, `.jpeg`, `.gif`, `.svg`. Any other path, any other type and an empty path
  throw an `InvalidArgumentException` naming the component — when the container is
  built, or on render with `metadata_cache: false`. Fonts and images are preloaded:
  `<link rel="preload" as="font|image">` and a `Link` header. The properties of
  `Asset` are read-only, and `$type` holds the resolved type.
- **What to do:** declare the type of a path without one of those extensions —
  `#[Asset('https://cdn.example.com/lib', type: 'js')]`,
  `#[Asset('https://fonts.googleapis.com/css2?family=Inter', type: 'css')]`. Update
  HTML snapshots of fonts and images. Code that wrote to the properties of an
  `Asset` instance has to construct a new one.

#### Stylesheets carry `data-turbo-track="dynamic"`

- **Before:** `<link rel="stylesheet" href="…">`.
- **After:** `<link rel="stylesheet" href="…" data-turbo-track="dynamic">`. With
  Turbo 8, Drive removes the stylesheets of components the next page does not
  render; without Turbo the attribute does nothing.
- **What to do:** update HTML snapshots. To keep a stylesheet across Turbo
  navigations, opt out with `attributes: ['data-turbo-track' => false]`.

#### Tag attributes follow the Twig component convention

- **Before:** `['async' => false]` rendered `async=""` — which turns `async` on —
  and URLs were not escaped.
- **After:** `false` and `null` leave the attribute out, `true` renders it bare,
  and `href`/`src` are HTML-escaped.
- **What to do:** check assets with boolean attributes; `false` now means off.

#### Assets are injected into HTML responses only, once

- **Before:** every response containing the placeholder got the tags, and each
  placeholder got a full copy.
- **After:** only HTML responses (a Content-Type with `html`, or none and an HTML
  request format), and only at the first placeholder; the others are removed.
  `Content-Length` is updated.
- **What to do:** nothing, unless you relied on tags in a non-HTML response or at
  a second placeholder.

#### Co-located files are named by where they are served from

- **Before:** a CSS or JS file next to a component class was named relative to a
  Twig path, or by its bare file name outside every Twig path — a broken
  `<link>`/`<script>` unless the directory was both a Twig and an AssetMapper path
  (as `ux_components_dir` is). A template under a namespaced Twig path was named
  without its namespace and could not be loaded.
- **After:** the template gets its Twig name (`@ui/…` under a namespaced path), CSS
  and JS their AssetMapper logical path. A file without such a name is left out and
  reported by `debug:sdc` and, in debug, the log.
- **What to do:** nothing for components in `ux_components_dir`. For a component
  elsewhere, see [Where components can live](docs/components.md#where-components-can-live).

#### A template named explicitly wins

- **Before:** a `.html.twig` next to the class replaced the template named by the
  `template` argument of `#[AsTwigComponent]`, `#[AsSdcComponent]`,
  `#[AsLiveComponent]` or a `twig.component` tag.
- **After:** the named template is rendered; the co-located CSS and JS are still
  loaded.
- **What to do:** if a component relied on its co-located template, remove the
  `template` argument.

#### Anonymous components in `ux_components_dir`

- **Before:** a template without a class in `ux_components_dir` was not a component,
  and anonymous components got no assets.
- **After:** `src_component/Notice/Notice.html.twig` renders as
  `<twig:Notice:Notice>` with `Notice.css` and `Notice.js` next to it; Twig
  Components' `anonymous_template_directory` and component classes take precedence.
  A template next to a PHP file of the same name is not an anonymous component.
- **What to do:** nothing; existing names keep resolving as before.

### Stimulus

#### `controller` is set without the trait

- **Before:** only a component using the `Stimulus` trait and implementing
  `ComponentNamespaceInterface` had a `controller` variable. An embedded component
  (`<twig:X>…</twig:X>`) without them saw the `controller` of the template around it.
- **After:** every component in `component_namespace` gets its own, LiveComponents
  included; one it exposes itself (the trait, a public property,
  `#[ExposeInTemplate]`) is kept. `make:sdc-component` no longer generates the trait
  and the interface.
- **What to do:** nothing. You may remove `use Stimulus` and
  `implements ComponentNamespaceInterface` from components that do not call
  `getController()` in PHP.

#### Underscores in controller names

- **Before:** `UI\Date_Picker\Date_Picker` was named `UI--Date_Picker--Date_Picker`,
  while StimulusBundle registers `UI--Date-Picker--Date-Picker` for its
  `_controller.js`. A namespace segment was removed wherever it occurred in the
  class name, not only as its prefix.
- **After:** `_` becomes `-`, matching StimulusBundle, and only the
  `component_namespace` prefix is removed. `Stimulus::getController()` on a class
  outside `component_namespace` throws a `LogicException`; it used to return the
  whole class name with `\` as `--`.
- **What to do:** `stimulus_controller(controller)` renders the same as before. A
  hand-written `data-controller="{{ controller }}"` now names the registered
  controller. Replace a hard-coded `Date_Picker` style name in templates or JS.

#### `kernel.controller` without LiveComponent

- **Before:** a page controller using the `Stimulus` trait got the component
  namespace only when LiveComponent was installed; otherwise `getController()` threw.
- **After:** always.
- **What to do:** nothing.

### Live Components, Turbo and response headers

#### One `X-SDC-Assets` header replaces `X-SDC-Assets-CSS` and `X-SDC-Assets-JS`

- **Before:** a Live re-render got comma-separated public paths in
  `X-SDC-Assets-CSS` and `X-SDC-Assets-JS`, without attributes; the loader inserted
  every script as a classic one.
- **After:** one header, versioned JSON:
  `{"v":1,"assets":[{"type":"css|module|script","href":"…","attributes":{…}}]}`
  (see [The X-SDC-Assets header](docs/live-and-turbo.md#the-x-sdc-assets-header)).
  The loader builds the elements like the tags of a full page, modules as modules,
  with the page's CSP nonce, and warns in the console about a version it does not
  know.
- **What to do:** if you copied `sdc_loader_controller.js` into your application,
  use the one the bundle ships (`{{ stimulus_controller(sdc_loader_controller) }}`).
  Code or tests that read the old headers read
  `json_decode($response->headers->get('X-SDC-Assets'), true)['assets']` instead.

#### Turbo Frames, Turbo Streams and partials get the header

- **Before:** only Live re-renders got headers, recognised by an `Accept` header
  equal to `application/vnd.live-component+html`; every other response got tags at
  the placeholder, if it had one.
- **After:** Live re-renders (an `Accept` listing that type among others too), Turbo
  Frame requests — even when answered with a whole page with the placeholder —,
  Turbo Streams and any other HTML response without the placeholder get the header
  instead of tags. The loader controller applies it for Live Components and on
  `turbo:before-fetch-response`.
- **What to do:** for Turbo Frames or Streams, attach the loader controller to
  `<body>` ([The loader controller](docs/live-and-turbo.md#the-loader-controller)).
  Tests asserting tags in a Turbo Frame response assert on the header.

### PHP API

#### Classes not meant for applications are internal

1.0 defines its public API (see
[Backward compatibility](README.md#backward-compatibility)). Everything else in
`src/` is marked `@internal` and may change in any release; IDEs and static
analysers may flag its use outside the bundle. `Configuration`, `SdcExtension`, `SdcMetadataRegistry` and
`ComponentMetadataResolver` are also `final` now. Among them:

- `Service\AssetRegistry` — declare assets with `#[Asset]` or `#[AsSdcComponent]`
  instead of adding them to the registry.
- `Runtime\SdcMetadataRegistry` — inspect components with
  `debug:sdc --format=json` ([Diagnostics](docs/diagnostics.md#json-output)).
- `Service\ComponentMetadataResolver`, the event listeners, compiler passes,
  `Configuration`, `SdcExtension`, `Twig\AssetExtension`, the maker.

The changes below are listed for code that used these classes anyway.

#### `SdcMetadataRegistry`

- **Before:** `getMetadata(string $class): array|string|null` — the assets of a
  class, or for the key `<class>_template` its template. `sdc_metadata.php` was a
  flat map of those keys.
- **After:** `getAssets(string $class): array`, `getTemplate(string $class): ?string`,
  `getComponentMetadata(string $class): ComponentMetadata` and
  `getAnonymousComponentMetadata(string $template): ComponentMetadata`. The
  constructor is `(string $cachePath, bool $cacheEnabled = true, ?LoggerInterface $logger = null)`.
  `sdc_metadata.php` is
  `['components' => [class => ['assets', 'template', 'problems']], 'anonymous' => [template => ['assets', 'problems']]]`,
  each asset with a `source`; it is regenerated with the container.
- **What to do:** replace `getMetadata($class)` with `getAssets($class)` and
  `getMetadata($class.'_template')` with `getTemplate($class)` — or move to
  `debug:sdc --format=json`.

#### `ComponentMetadataResolver`

- **Before:** `resolveMetadata(string $class, string $componentName, array &$allMetadata = []): array`.
- **After:** `resolve(string $class): ComponentMetadata` (an immutable value object
  with `assets`, `template` and `problems`), plus `resolveAnonymous()` and
  `locateTemplate()`. The constructor takes the Twig paths and the AssetMapper
  paths separately.
- **What to do:** do not construct or call it; it is internal.

#### `SdcBundle`

- **Before:** extended `AbstractBundle`, with the configuration in
  `config/definition.php`.
- **After:** extends `Bundle`; the configuration is
  `DependencyInjection\Configuration`, `getPath()` is still the package root.
- **What to do:** nothing, unless you extended `SdcBundle`.

### Maker

#### `make:sdc-component` needs a consistent configuration

- **Before:** without `component_namespace` it assumed `App\Component`, and it wrote
  the class where Composer's PSR-4 mapping put it and the other files into
  `ux_components_dir`, wherever those were.
- **After:** it stops before writing anything when `component_namespace` is not set,
  when Composer does not autoload it, or when Composer maps it to a directory other
  than `ux_components_dir`.
- **What to do:** set `component_namespace` to the namespace Composer maps to
  `ux_components_dir`.

#### `--action` generates a page that opens on a Flex application

- **Before:** the page template extended `layout.html.twig` and filled `content`;
  the route was `/en`, named `app.<name>`, for every page.
- **After:** the template extends `layout.html.twig` if Twig finds one, else
  `base.html.twig`, and fills `content` if that template has it, else `body`; the
  maker stops when there is neither. The route path is derived from the name
  (`Page:Blog:BlogPost` → `/blog/blog-post`), the name follows `make:controller`
  (`app_blog_blog_post`). The controller is a `final` invokable `AbstractController`.
  New options: `--route-path` and `--layout`.
- **What to do:** nothing for pages already generated. Pass `--route-path` or
  `--layout` where the derived values do not fit.

#### `--stimulus` needs StimulusBundle

- **Before:** it generated a template calling `stimulus_controller()`, which does
  not exist without StimulusBundle, so the component failed to render.
- **After:** without StimulusBundle, `--stimulus` stops before writing anything and
  the question is not asked.
- **What to do:** `composer require symfony/stimulus-bundle`, or leave `--stimulus`
  out.
