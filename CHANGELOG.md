# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- A web profiler panel (*UX SDC*), in debug with the profiler enabled: the
  components a request rendered and the assets each contributed (discovered,
  `#[Asset]`, `#[AsSdcComponent]`), how the response got them (tags at the
  placeholder, preload, `X-SDC-Assets` header, or nothing and why), and the
  problems of that request — missing placeholder, import map after the modules,
  assets outside the asset map, an oversized header, component metadata
  problems. The toolbar shows the number of components and assets, in yellow
  when there is a problem. Nothing is recorded outside the profiler.
- `ux_sdc.exclude_templates_from_asset_map` (default `true`): keeps the Twig
  templates of `ux_components_dir` out of the AssetMapper. Set it to `false`
  only if you really want them mapped.
- `ux_sdc.metadata_cache` (`auto` | `true` | `false`, default `auto`): selects
  where component metadata (asset paths and template) comes from. `true` resolves
  it once, when the container is built, into `%kernel.cache_dir%/sdc_metadata.php`;
  `false` resolves it on render from the files next to the component class, so new
  files are picked up without `cache:clear`; `auto` means `false` when
  `kernel.debug` is on and `true` otherwise. The value selects services at build
  time and therefore cannot come from an environment variable.
- `debug:sdc [name] [--format=txt|json] [--strict]`: lists every component as
  the bundle renders it — template and where it comes from (next to the class,
  named by the registration, or the Twig Components default), CSS and JS with
  their public paths and where each was declared (discovered, `#[Asset]`,
  `#[AsSdcComponent]`), the Stimulus controller name `{{ controller }}` gets
  and whether StimulusBundle has it, and the routes of the controllers next to
  the class — and flags what keeps a part from loading: a file left out because
  it is outside every Twig or AssetMapper path, an asset path the asset map does
  not know, an asset of a type other than `css`/`js`, a missing template, a
  template using `{{ controller }}` without a controller of that name. It reads
  the metadata the pages use (the build-time cache with `metadata_cache: true`).
  A name, a class or a part of either narrows the list; a single match is shown
  in detail. Like other `debug:*` commands it exits with 0; `--strict` fails it
  when a problem is found, for CI.
- Diagnostics for component assets that silently went missing, logged through the
  application's `logger` service (channel `ux_sdc` with MonologBundle; the bundle
  now requires `psr/log`). Each message says what to change:
  - warning, in debug only: a Twig file next to a component class is outside
    every Twig path, or a CSS or JS file outside every AssetMapper path, and is
    therefore not loaded. At build time
    (`metadata_cache: true`) the warning goes to the container compiler log, which
    the profiler shows under *Logs › Container*;
  - warning, in debug only: an HTML page with a `<head>` renders components with
    assets but has no `render_component_assets()` placeholder. Fragments without a
    `<head>` (Turbo Frames, partials) are not reported;
  - warning, in debug only: a component asset path the asset map does not know
    (a typo in `#[Asset]`/`#[AsSdcComponent]`, a file AssetMapper does not serve)
    is linked as it is. URLs (`https://…`, `//…`, `/…`, `data:…`) are linked as
    they are on purpose and not reported;
  - error, in every environment: `metadata_cache` is on but
    `%kernel.cache_dir%/sdc_metadata.php` does not exist (cache not warmed where
    the application runs, or a different runtime cache directory). The page is
    still delivered, without component CSS and JS.
- The `controller` template variable is set for every component in
  `component_namespace`, LiveComponents included, without the `Stimulus` trait
  or `ComponentNamespaceInterface`. A `controller` the component exposes itself
  (a public property, `#[ExposeInTemplate]`, the trait) is kept; one inherited
  from the template around an embedded component (`<twig:X>…</twig:X>`) is not.
  Without `component_namespace`, and for classes outside it, nothing is set.
  The trait and the interface keep working, for the name in PHP
  (`getController()`). `make:sdc-component` no longer generates them.
- `make:sdc-component --route-path=PATH --layout=TEMPLATE`: the path of the
  `--action` route and the template the page extends, instead of the derived and
  detected ones. Either option implies `--action`.
- Route import `type: ux_sdc` (`config/routes/ux_sdc.yaml` with `resource: .`,
  `type: ux_sdc`): loads the `#[Route]` attributes of the classes in
  `ux_components_dir`, such as the pages of `make:sdc-component --action`, on
  every supported Symfony version. Until now they were loaded only on Symfony
  7.4+ through `resource: routing.controllers`, and an application on 6.4–7.3 had
  to import the directory by path. A route imported by both, or also by such a
  path import, is registered once. Before the directory exists the import loads
  nothing (and the routes appear once it is created); without
  `component_namespace` it loads nothing either.
- Anonymous components (a template without a class) in `ux_components_dir`:
  `src_component/Notice/Notice.html.twig` renders as `<twig:Notice:Notice>`, named
  after its path like a class there (`Notice/index.html.twig` is `Notice`). Twig
  Components' `anonymous_template_directory` keeps precedence, and a class of
  the same name wins. `debug:sdc` lists anonymous components, marked
  `(anonymous)`, with their assets.
- `SdcMetadataRegistry::getAnonymousComponentMetadata(string $template)` and
  `ComponentMetadataResolver::resolveAnonymous(string $template)`: the metadata
  of an anonymous component, by its template as Twig Components names it;
  `ComponentMetadataResolver::locateTemplate(string $template)`: the file a Twig
  name stands for.
- `ux_sdc.routing.enabled` (default `true`; `routing: false` is a shorthand):
  `false` makes the `ux_sdc` import load nothing and keeps the component classes
  out of `resource: routing.controllers` (Symfony 7.4+). An import of the
  directory written by the application itself is not affected.
- `ux_sdc.csp_nonce_provider` (default `null`): the service ID of a
  `Tito10047\UX\Sdc\Csp\NonceProviderInterface`, whose Content Security Policy
  nonces are put on the injected `<script>` (script nonce) and `<link>` (style
  nonce) tags. It is asked only for the kinds of tags a response gets.
  `Tito10047\UX\Sdc\Csp\NelmioNonceProvider`, registered when
  NelmioSecurityBundle is enabled, uses the nonces of Nelmio's `csp` feature;
  it is opt-in, because a nonce in the policy makes browsers ignore
  `'unsafe-inline'`. An asset's own `nonce` attribute wins (`false` leaves it out).
  The loader controller gives the assets of a Live re-render the nonce of the page.
- Warning, in debug only: a page has its import map after
  `render_component_assets()`, so component module scripts come before it.
- Turbo Frames and Streams get the assets of the components they bring: their
  responses (a `Turbo-Frame` request, a `text/vnd.turbo-stream.html` response or
  the `turbo_stream` request format) list them in the `X-SDC-Assets` header — a
  frame response even when it is a whole page with the placeholder, since Turbo
  drops its `<head>` — and the loader controller adds the missing ones on
  `turbo:before-fetch-response`. So does any other HTML response without the
  placeholder (an htmx partial, a fragment fetched by the application), for a
  script of its own to read. The loader does not depend on Turbo and does
  nothing without it; it also takes the script nonce from Turbo's
  `<meta name="csp-nonce">` when no script of the page has one.
- Warning, in debug only: the `X-SDC-Assets` header of a response is longer than
  2 KB — nginx in front of PHP-FPM answers 502 once all the response headers
  exceed `fastcgi_buffer_size` (4k or 8k by default).
- `#[Asset]` preloads fonts and images: the types `font` and `image` (constants
  `Asset::FONT`, `Asset::IMAGE`, next to `Asset::CSS` and `Asset::JS`) render
  `<link rel="preload" as="font|image">` with the MIME type of the extension —
  and `crossorigin` for a font, which browsers fetch in CORS mode — and the same
  `Link: rel="preload"` header. They are not listed in the `X-SDC-Assets` header
  of fragments. `debug:sdc` shows them in a *Preload* column.

### Changed

- README rewritten against the current code: every example verified in a Flex-style
  application; one consistent `App\Component\` ↔ `src_component/` mapping; the
  component name (`<twig:Alert:Alert />`), `#[Asset]` and the events actually
  used are documented correctly; new sections for requirements, the `make:sdc-component` options and
  what the generated `--action` code still needs per Symfony version, a complete
  configuration reference, known limitations and non-goals.
- **Behaviour change:** the metadata pipeline is now selected by the debug flag
  instead of the environment name. Until now only an environment named exactly
  `dev` resolved metadata on render; every other one, debug or not, used the
  build-time cache. With the default `metadata_cache: auto`:
  - any environment running with debug on (`staging` with `APP_DEBUG=1`,
    `prod_debug`, …) now resolves metadata on render;
  - a `dev` environment running with debug off now uses the build-time cache;
  - `test` with debug on (the Symfony default for `APP_ENV=test`) now resolves
    metadata on render, so a test that reads `SdcMetadataRegistry` directly finds
    it empty. Rendered output is the same in both pipelines.
  Set `metadata_cache: true` or `false` to keep the previous behaviour of a
  particular environment.
- **BC break:** `SdcMetadataRegistry::getMetadata()`, which returned the assets
  of a class or, for a `<class>_template` key, its template
  (`array|string|null`), is replaced by `getAssets(string $class): array` (always
  a list, empty when there are none) and `getTemplate(string $class): ?string`.
  Its constructor takes `bool $cacheEnabled` and an optional logger after
  `$cachePath`. The `sdc_metadata.php` format changed to
  `['components' => [class => ['assets' => [...], 'template' => ?string, 'problems' => [...]]], 'anonymous' => [template => ['assets' => [...], 'problems' => [...]]]]`,
  each asset with a `source` (`discovered`, `#[Asset]` or `#[AsSdcComponent]`);
  it is regenerated with
  the container, so no action is needed for the file itself.
- **BC break:** `ComponentMetadataResolver::resolveMetadata($class, $componentName, &$allMetadata)`
  is replaced by `resolve(string $class): ComponentMetadata`, an immutable value
  object with `assets` (each with its `source`), `template` and `problems` (the
  files next to the class it had to leave out, as PSR-3 message and context;
  they are reported whether or not the resolver logs them). The constructor now reads
  `(array $twigRoots, array $assetRoots, bool $autoDiscoveryEnabled, ?LoggerInterface $logger = null, bool $debug = false, array $classesWithExplicitTemplate = [])`:
  Twig paths and AssetMapper paths are separate, each a list of directories or
  a map of directory => namespace, as in `twig.paths` and
  `framework.asset_mapper.paths`.
- **Behaviour change:** single-directory components are supported inside
  `ux_components_dir`, or any directory that is both a Twig path and an
  AssetMapper path (see *Where components can live* in the README). A CSS or JS
  file next to a component class is now named by its AssetMapper logical path,
  relative to the AssetMapper path it sits in — it used to be named relative to
  a Twig path, which AssetMapper could not serve unless the two happened to
  coincide, or, outside every Twig path, by its bare file name (`Alert.css`).
  Either way the result was a broken `<link>`/`<script>`; such a file is now
  left out, and reported in debug. For `ux_components_dir` nothing changes.
- **Behaviour change:** the Stimulus controller name (`{{ controller }}`,
  `Stimulus::getController()`) now follows StimulusBundle's naming exactly:
  `_` in the class name below `component_namespace` becomes `-`, as in the name StimulusBundle
  registers for the `_controller.js` file. `stimulus_controller(controller)`
  renders the same as before; a hand-written `data-controller="{{ controller }}"`
  on a class with an underscore now names the registered controller instead of
  one that does not exist. `getController()` on a class outside the component
  namespace now throws a `LogicException`; it used to return the whole class
  name with `\` as `--`, which no controller of the component directory has.
- The bundle now requires `symfony/filesystem` directly; it was already
  installed with FrameworkBundle.
- Every `ux_sdc` configuration option is now described, in English, in
  `config:dump-reference ux_sdc`; the descriptions used to be in Slovak, and
  `stimulus` had none.
- The package archive installed by Composer contains only what an application
  needs (`src/`, `config/`, `assets/`, `templates/` and the documentation). The
  tests, the end-to-end setup, tool configuration and an internal specification
  (`zadanie.md`, `.junie/`) are no longer shipped.
- **Behaviour change:** `make:sdc-component --action` generates a page that opens
  on a Flex application without being edited, once its route is loaded:
  - the page template extends `layout.html.twig` if Twig finds one, else
    `base.html.twig`, and fills the `content` block if that template has one
    (also when inherited), else `body`. It used to extend `layout.html.twig` with
    `content` unconditionally, which a Flex application does not have. Without
    a template to extend, or with neither block in it, the maker stops and asks
    for `--layout`;
  - the route path is derived from the component name, kebab-cased, without a
    leading `Page` namespace (`Page:Blog:BlogPost` → `/blog/blog-post`), instead
    of `/en` for every page, and the route name follows `make:controller`
    (`app_blog_blog_post` instead of `app.blogpost`);
  - the controller is a `final` invokable `AbstractController` with a
    `Response` return type imported, and the maker prints the route import the
    application needs (`type: ux_sdc`, or the directory import when
    `ux_sdc.routing.enabled` is off).
- **Behaviour change:** `make:sdc-component` stops, before writing anything, when
  `component_namespace` is not set (it used to assume `App\Component`), when
  Composer does not autoload it, or when Composer maps it to another directory
  than `ux_components_dir`. It used to write the class in one directory and its
  template and assets in another, where nothing found them.
- **Behaviour change:** without `symfony/stimulus-bundle`, `make:sdc-component`
  no longer asks whether to generate a Stimulus controller, and `--stimulus`
  stops before writing anything. The generated template called
  `stimulus_controller()`, a Twig function of StimulusBundle, so the component
  failed to render.
- **Behaviour change:** component scripts are loaded as ES modules,
  `<script src="…" type="module">` — discovered ones, `#[AsSdcComponent(js: …)]`
  and `#[Asset]` alike. AssetMapper serves JavaScript as modules: a component
  script with an `import` statement failed as a classic script, and bare imports
  resolve only through the import map. A module runs after the page is parsed, in
  strict mode and in a scope of its own, so a script relying on running
  immediately in `<head>` or on its top-level variables being global has to opt
  out with `attributes: ['type' => false]` (classic script; any other `type`
  value is kept as it is). `render_component_assets()` has to come after
  `{{ importmap() }}`: a browser that does not merge import maps ignores one that
  comes after a module script, and every bare import of the page then fails.
- Module scripts get no `Link: rel="preload"` header any more (`as="script"`
  fetches a module in the wrong mode, so it was downloaded twice; a
  `rel="modulepreload"` header would start loading modules before the import map
  is parsed). Stylesheets and classic scripts keep theirs.
- **BC break:** the `X-SDC-Assets-CSS` and `X-SDC-Assets-JS` headers of Live
  re-renders are replaced by one `X-SDC-Assets` header, JSON:
  `{"v":1,"assets":[{"type":"css","href":"…","attributes":{…}},{"type":"module","href":"…"}]}`.
  Each entry says whether it is a stylesheet, a module or a classic script, and
  carries the attributes of the tag (`defer`, `integrity`, `media`, …), which the
  comma-separated headers dropped; a URL with a comma no longer splits in two.
  The loader controller builds the elements like the tags of a full page and
  inserts module scripts as modules (every script used to be inserted as a
  classic one). Code reading the old headers has to read the new one; the
  format is documented in the README and versioned by `v`.
- **Behaviour change:** injected stylesheets carry `data-turbo-track="dynamic"`,
  so Turbo Drive (8.0+) removes the stylesheets of components the next page does
  not render instead of keeping every stylesheet a visit ever loaded. Opt out per
  asset with `attributes: ['data-turbo-track' => false]`; without Turbo the
  attribute does nothing.
- A Live Component request is recognised by an `Accept` header that lists
  `application/vnd.live-component+html` among other types too, not only by one
  that is exactly that.
- **BC break:** the type of an `#[Asset]` without `type` is told by the extension
  of its path from a fixed list — `.css`; `.js`, `.mjs`; `.woff2`, `.woff`,
  `.ttf`, `.otf`; `.avif`, `.webp`, `.png`, `.jpg`, `.jpeg`, `.gif`, `.svg`
  (the query string of a URL does not count). A path that tells none used to be
  loaded as a script; now it fails the container build (with
  `metadata_cache: false`, the render) with an `InvalidArgumentException` naming
  the component. Declare the type of such a path:
  `#[Asset('https://cdn.example.com/lib', type: 'js')]`,
  `#[Asset('https://fonts.googleapis.com/css2?family=Inter', type: 'css')]`.
- **BC break:** `#[Asset]` accepts only the types `css`, `js`, `font` and
  `image`, and an empty path is an error; any other type used to be accepted
  and the asset silently never loaded. Its properties are read-only, and
  `$type` holds the type the asset loads as — declared or told by the path
  (`null` only for an `#[Asset]` without a path, which is still ignored).

### Fixed

- Anonymous components get their CSS and JS: the stylesheet and script named
  after the template, next to it (`Notice/Notice.css` and `Notice/Notice.js` for
  `Notice/Notice.html.twig`), in both metadata pipelines
  ([#3](https://github.com/tito10047/ux-sdc/issues/3)). They used to get none.
  The usual rules apply: a file outside every AssetMapper path — next to a
  template in `templates/components/` — is left out and reported. Anonymous
  components get no `controller` variable.
- Installing the bundle before `ux_components_dir` exists no longer breaks every
  page of the application. The directory was registered as a Twig path and an
  AssetMapper path regardless, and Twig refuses a path that does not exist, so
  the `twig` service could not be created (`The "…/src_component" directory
  does not exist`); `debug:asset-map` failed the same way. A missing directory
  is now left out, like TwigBundle leaves out a missing `templates/`, and a
  debug container is rebuilt as soon as the directory is created. A container
  built without it and never rebuilt — production, warmed up before the
  directory existed — needs `cache:clear`.
- A controller implementing `ComponentNamespaceInterface` (a page controller
  using the `Stimulus` trait) receives the component namespace on
  `kernel.controller` whether or not LiveComponent is installed. Without
  LiveComponent the listener was not registered, and `getController()` threw.
- A template named explicitly — the `template` argument of `#[AsTwigComponent]`,
  `#[AsSdcComponent]` or `#[AsLiveComponent]`, or of a `twig.component` service
  tag — is rendered again. A `.html.twig` file next to the component class used
  to replace it silently. The co-located CSS and JS are still loaded.
- The co-located template of a component under a Twig path registered with a
  namespace (`twig.paths: {'%kernel.project_dir%/lib/ui': ui}`) is now named
  `@ui/…`. It was named without the namespace, so Twig could not load it and
  the page failed with "Unable to find template". A directory registered both
  in the main namespace and with a namespace (as `ux_components_dir` is, also as
  `@component`) keeps the name without one. Twig paths and AssetMapper paths are
  now read from the services that use them, so relative paths, bundle
  directories and `twig.default_path` are all taken into account.

- `#[AsSdcComponent]` works on Symfony 6.4 and 7.0. There a class carrying only
  `#[AsSdcComponent]` was never registered as a Twig component ("Unknown
  component"), because their dependency injection autoconfigures an attribute by
  its exact class and did not recognise the subclass of `#[AsTwigComponent]`.
  The bundle now registers TwigComponent's own configurator for it. Symfony 7.1
  and later were not affected. PHP 8.1 with Symfony 6.4 is now part of the CI
  test matrix, so the lowest supported versions are actually tested.

- The lowest supported `symfony/ux-twig-component` is now 2.20 (`^2.20|^3.0`,
  was `^2.13|^3.0`). The bundle calls `ComponentMetadata::isAnonymous()`, which
  TwigComponent added in 2.20; on 2.13–2.19 every component rendered without the
  metadata cache (the default in debug) failed with "Call to undefined method".
  CI now has a `--prefer-lowest` job, and every job installs the Symfony version
  its name states — before, all of them silently got the newest one.

- Without the metadata cache (`metadata_cache: false`, the default in debug), the
  co-located files of a component in a directory added through `twig.paths`
  were not found, although the build-time cache found them: the two pipelines
  looked in different Twig paths. Both now use the same list, application
  `twig.paths` included.

- Component Twig templates are no longer published to `public/assets/`.
  `ux_components_dir` is registered as an AssetMapper path so that component
  CSS and JS can be served, which also mapped every `.html.twig` next to them:
  `asset-map:compile` copied the template sources into `public/assets/` and
  `manifest.json`, where anyone could download them. The bundle now adds the
  directory's `*.twig` files (nested directories included) to
  `framework.asset_mapper.excluded_patterns`; patterns the application defines
  itself are kept. The manual `excluded_patterns` workaround is no longer
  needed and can be removed.

- Component assets no longer leak from one request into the next in
  long-running runtimes (FrankenPHP worker mode, RoadRunner, Swoole). There the
  container survives between requests, and `AssetRegistry` — which collects the
  CSS/JS of the components rendered for one response — was never emptied, so
  every page also received the assets of every page served before it by the
  same worker. The registry now implements `ResetInterface` and is tagged
  `kernel.reset`, so Symfony's `services_resetter` clears it between requests.
  Classic PHP-FPM setups were not affected. `AssetRegistry::clear()` still
  exists and behaves as before.
- The bundled `sdc-loader` Stimulus controller no longer breaks applications
  that do not have `symfony/ux-live-component` installed. It is registered in
  every application, but imported `@symfony/ux-live-component` (without using
  it), so the browser failed with *Failed to resolve module specifier*. The
  controller now has no dependency besides Stimulus. While at it, it no longer
  hooks into the same Live Component twice when the component reconnects,
  removes its hooks on `disconnect()`, recognises an asset already on the page
  by its resolved URL instead of a suffix match, and loads injected scripts in
  the order the server sent them.

- Installing the bundle no longer unregisters the application's own Stimulus
  controllers. The bundle prepended its component directory to
  `stimulus.controller_paths`, and a prepended value suppresses StimulusBundle's
  default (`%kernel.project_dir%/assets/controllers`), so every controller in
  `assets/controllers/` silently stopped working unless the application had its
  own `config/packages/stimulus.yaml`. The component directory is now added to
  the controller paths StimulusBundle resolved from the application
  configuration, after the fact: that configuration — default included — is
  left untouched, and a directory the application already lists is not added a
  second time. A component directory that does not exist yet is skipped instead
  of making StimulusBundle fail. The `stimulus.yaml` workaround is no longer
  needed and can be removed.
- `ux_sdc: { stimulus: false }` now disables the Stimulus integration. The
  shorthand was accepted by the configuration but ignored, so the component
  directory was registered anyway; only `stimulus: { enabled: false }` worked.
- The preload `Link` header for component assets no longer depends on the
  order in which listeners happen to be registered. The bundle hands the links
  to WebLink, whose listener writes the header; both ran on `kernel.response`
  at priority 0, so the header was only sent because the bundle's listener
  came first. It now runs at priority 64, before WebLink and before the core
  listeners that finalise the response (`Response::prepare()`, the profiler).
- Assets are only injected into HTML responses. A JSON, plain-text or other
  non-HTML response that contains the placeholder is left untouched; a
  response without a `Content-Type` counts as HTML unless the request format
  says otherwise.
- A page with several placeholders (a layout and a partial both calling
  `render_component_assets()`) gets the tags once, where the first placeholder
  is; the others are removed. Each placeholder used to get a full copy, so
  every script ran twice.
- A `Content-Length` set by the controller is updated after the injection
  instead of describing the shorter original content.
- `#[Asset]` attributes follow the convention of Twig component attributes:
  `false` and `null` leave the attribute out and `true` renders it bare.
  `['async' => false]` used to render `async=""`, which turns async *on*.
  Asset URLs are now HTML-escaped in `href` and `src`.
- `ux_sdc.placeholder` can no longer be configured empty, which would match
  every response.
- `#[Asset('fonts/inter.woff2')]` — any path without `type` other than `.css` —
  was loaded as `<script src="…woff2">` and preloaded `as="script"`. It is
  preloaded as a font now (see *Added*), and a path whose type is unknown is
  never loaded as a script.

## [0.4.0] - 2026-10-02

### Added

- Support for **Symfony UX 3**: `symfony/ux-twig-component` is now
  `^2.13|^3.0`, and `symfony/ux-live-component` / `symfony/stimulus-bundle`
  likewise in dev. No source change was needed — the constraint was simply
  narrower than the code. An application on Symfony 8 no longer has to stay on
  UX 2 because of this bundle.
- A CI job, *Tests (UX 2.x)*, that pins Symfony UX back to 2.x. The other jobs
  resolve to the highest version, so without it the 2.x half of the supported
  range silently stopped being tested the day UX 3 was released.
- `make:sdc-component --action` generates an action class and its template
  alongside the component.

### Fixed

- `lint:container` no longer fails in applications that install this bundle.
  `SdcExtension` registered `ux_sdc.ux_components_dir` as a *service* whose
  class was the literal string `"string"`, plus an `app.ui_components.dir`
  alias pointing at it. Nothing ever instantiated either, so applications ran —
  but `lint:container` walks every definition and stopped with
  'class "string" does not exist'. Both are gone; the two parameters of the
  same names, which is what everything actually used, are unchanged.

## [0.3.0] - 2026-03-25

### Changed

- **Breaking Change**: Renamed `UxSdcBundle` to `SdcBundle` to follow official Symfony bundle naming conventions and reflect the updated bundle name `Tito10047\UX\Sdc\SdcBundle`.
