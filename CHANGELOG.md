# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - Unreleased

1.0 defines the public API of the bundle — see *Backward compatibility* in the
README — and marks everything else `@internal`. Every change below that can affect
an application is described, with what to do, in [UPGRADE.md](UPGRADE.md). The
documentation moved from the README to [`docs/`](docs/installation.md).

### Added

- Add the `metadata_cache` option (`auto` | `true` | `false`, default `auto` =
  `false` in debug, `true` otherwise): whether component metadata is resolved once,
  into `%kernel.cache_dir%/sdc_metadata.php`, when the container is built, or on
  render from the files next to the component. It selects services at build time
  and cannot come from an environment variable.
- Add the `debug:sdc [name] [--format=txt|json] [--strict]` command: the template,
  CSS, JS, preloaded fonts and images, Stimulus controller and routes of every
  component, anonymous ones included, read from the same metadata the pages use,
  with what keeps any part from loading — a file outside every Twig or AssetMapper
  path, an asset path the asset map does not know, a missing template, a
  `{{ controller }}` StimulusBundle has no controller for. `--strict` exits with 1
  on a problem, for CI.
- Add a web profiler panel (*UX SDC*), in debug with the profiler enabled: the
  components a request rendered, the assets each asked for and where they were
  declared, how the response got them (tags, preload, `X-SDC-Assets` header, or
  nothing and why), and the problems of that request. The toolbar turns yellow
  on a problem.
- Add diagnostics through the application's `logger`, on the `ux_sdc` channel with
  MonologBundle. Warnings, in debug only: a co-located file left out because it is
  outside every Twig or AssetMapper path (at build time into the container compiler
  log); a page with a `<head>` and component assets but no placeholder; an asset
  path the asset map does not know (URLs excepted); the import map after
  `render_component_assets()`; an `X-SDC-Assets` header longer than 2 KB, which
  nginx in front of PHP-FPM may answer with a 502. Error, always: the metadata cache
  is on but `sdc_metadata.php` is missing.
- Add the `controller` template variable to every component in
  `component_namespace`, LiveComponents included, without the `Stimulus` trait or
  `ComponentNamespaceInterface`. A `controller` the component exposes itself is
  kept; one inherited from the template around an embedded component is not.
- Support anonymous components in `ux_components_dir`:
  `src_component/Notice/Notice.html.twig` renders as `<twig:Notice:Notice>`
  (`Notice/index.html.twig` as `<twig:Notice>`). Anonymous components get the CSS
  and JS named after their template, next to it, in both metadata pipelines —
  they used to get none ([#3](https://github.com/tito10047/ux-sdc/issues/3)).
  Twig Components' `anonymous_template_directory` and component classes take
  precedence.
- Support Turbo Frames and Streams: their responses — and any other HTML response
  without the placeholder, such as an htmx partial — list the assets of their
  components in the `X-SDC-Assets` header, which the loader controller applies on
  `turbo:before-fetch-response`. A Turbo Frame response gets the header even when it
  is a whole page with the placeholder, since Turbo drops its `<head>`.
- Add the `font` and `image` types to `#[Asset]` (constants `Asset::CSS`,
  `Asset::JS`, `Asset::FONT`, `Asset::IMAGE`): `<link rel="preload" as="font|image">`
  with the MIME type of the extension, `crossorigin` for fonts, and the same `Link`
  header.
- Add Content Security Policy nonces: the `csp_nonce_provider` option takes the
  service ID of a `Csp\NonceProviderInterface`, whose nonces go on the injected
  tags; `Csp\NelmioNonceProvider`, registered with NelmioSecurityBundle, uses
  Nelmio's. Opt-in, since a nonce makes browsers ignore `'unsafe-inline'`. The
  loader controller gives the assets it inserts the nonce of the page.
- Add the `type: ux_sdc` route import, which loads the `#[Route]` controllers of
  `ux_components_dir` — such as the pages of `make:sdc-component --action` — on
  every supported Symfony version, and the `routing.enabled` option. A route
  imported twice, also through `resource: routing.controllers`, is registered once.
- Add the `--route-path` and `--layout` options to `make:sdc-component`.
- Propose a Flex recipe to `symfony/recipes-contrib`: it registers the bundle,
  creates `config/packages/ux_sdc.yaml`, `config/routes/ux_sdc.yaml` and
  `src_component/`, and adds `{{ render_component_assets() }}` after
  `{{ importmap() }}`. Until it is merged, see *Without the recipe* in
  [`docs/installation.md`](docs/installation.md#without-the-recipe).

### Changed

- **BC break:** load component scripts as ES modules, `<script type="module">`,
  without a `Link` preload; `render_component_assets()` has to come after
  `{{ importmap() }}`. Opt out per asset with `attributes: ['type' => false]`
  ([upgrade](UPGRADE.md#component-scripts-are-es-modules)).
- **BC break:** replace the `X-SDC-Assets-CSS` and `X-SDC-Assets-JS` headers of
  Live re-renders with one `X-SDC-Assets` header, versioned JSON with the type and
  attributes of every asset. A Live request is recognised by an `Accept` header
  listing `application/vnd.live-component+html` among other types too
  ([upgrade](UPGRADE.md#one-x-sdc-assets-header-replaces-x-sdc-assets-css-and-x-sdc-assets-js)).
- **BC break:** validate the type of `#[Asset]`: `css`, `js`, `font` or `image`,
  told by the extension when omitted; an unknown type, a path that tells none and an
  empty path fail with an exception naming the component. The properties of `Asset`
  are read-only. A font used to be loaded as `<script>`
  ([upgrade](UPGRADE.md#asset-types-are-validated)).
- **BC break:** mark every class outside the public API `@internal`; make
  `Configuration`, `SdcExtension`, `SdcMetadataRegistry` and
  `ComponentMetadataResolver` `final`; change the API of `SdcMetadataRegistry` and
  `ComponentMetadataResolver` and the format of `sdc_metadata.php`
  ([upgrade](UPGRADE.md#php-api)).
- **Behaviour change:** select the metadata pipeline by the debug flag
  (`metadata_cache: auto`) instead of the environment name `dev`
  ([upgrade](UPGRADE.md#the-metadata-pipeline-follows-the-debug-flag-not-the-environment-name)).
- **Behaviour change:** mark injected stylesheets `data-turbo-track="dynamic"`, so
  that Turbo 8 removes the stylesheets of components the next page does not render
  ([upgrade](UPGRADE.md#stylesheets-carry-data-turbo-trackdynamic)).
- **Behaviour change:** name a co-located template by its Twig name and CSS/JS by
  their AssetMapper logical path, and leave out (and report) a file without one; such
  a file used to get a broken `<link>`/`<script>`
  ([upgrade](UPGRADE.md#co-located-files-are-named-by-where-they-are-served-from)).
- **Behaviour change:** derive Stimulus controller names like StimulusBundle, with
  `_` as `-`; `Stimulus::getController()` throws on a class outside
  `component_namespace` ([upgrade](UPGRADE.md#underscores-in-controller-names)).
- **Behaviour change:** make `make:sdc-component --action` generate a page that
  opens on a Flex application — layout and block detected, route path derived from
  the name, route name as `make:controller` gives it; stop before writing anything
  when `component_namespace` is missing or does not match Composer's PSR-4 mapping
  of `ux_components_dir`, and on `--stimulus` without StimulusBundle; no longer
  generate the `Stimulus` trait and `ComponentNamespaceInterface`
  ([upgrade](UPGRADE.md#maker)).
- Raise the lowest `symfony/ux-twig-component` to 2.20 (`^2.20|^3.0`): the bundle
  calls `ComponentMetadata::isAnonymous()`, added in 2.20.
- Require `symfony/twig-bundle`, `psr/log`, `symfony/filesystem` and
  `symfony/service-contracts` directly. Without TwigBundle, which
  `symfony/ux-twig-component` does not install, `composer require` on a bare
  `symfony/skeleton` failed.
- Describe every option in English in `config:dump-reference ux_sdc`.
- Rewrite the documentation against the code and split it into `docs/`; add
  `UPGRADE.md`. The package archive ships `src/`, `config/`, `assets/`,
  `templates/`, `skeleton/` and the documentation, without tests and tooling.

### Deprecated

- Deprecate the `app.ui_components.dir` parameter, to be removed in 2.0; use
  `ux_sdc.ux_components_dir` ([upgrade](UPGRADE.md#appui_componentsdir-is-deprecated)).

### Removed

- **BC break:** remove `SdcMetadataRegistry::getMetadata()`, replaced by
  `getAssets()` and `getTemplate()`, and
  `ComponentMetadataResolver::resolveMetadata()`, replaced by `resolve()`
  ([upgrade](UPGRADE.md#php-api)).
- Remove `config/definition.php`: `SdcBundle` extends `Bundle` instead of
  `AbstractBundle`, with the configuration in `DependencyInjection\Configuration`.

### Fixed

- Fix relative imports in component scripts (`import './format.js'`), which the
  browser requested unversioned and got a 404 for: the module script of every
  component is now an entry of AssetMapper's import map, so AssetMapper maps the
  files it imports, in debug and in `asset-map:compile`. The entries are the same
  on every page, for components inserted later by Live Components or Turbo too;
  `importmap.php` is not changed
  ([docs](docs/assets.md#imports-in-a-component-script)).
- Fix component assets leaking from one request into the next in long-running
  workers (FrankenPHP, RoadRunner, Swoole): `AssetRegistry` is reset between
  requests.
- Fix the loader controller breaking applications without
  `symfony/ux-live-component` (*Failed to resolve module specifier*). It also no
  longer hooks into a reconnecting Live Component twice, removes its hooks on
  `disconnect()`, recognises an asset already on the page by its resolved URL, and
  runs inserted scripts in the order the server sent them.
- Fix the bundle unregistering the application's own Stimulus controllers: its
  directory is added next to StimulusBundle's controller paths instead of replacing
  the default `assets/controllers`. A directory the application already lists is
  not added twice.
- Fix `stimulus: false` being ignored.
- Fix `asset-map:compile` publishing the Twig templates of `ux_components_dir` to
  `public/assets/`: they are excluded from the asset map
  (`exclude_templates_from_asset_map`, default `true`).
- Fix a missing `ux_components_dir` breaking every page (*The "…/src_component"
  directory does not exist*) and `debug:asset-map`: the directory is registered
  once it exists, and a debug container is rebuilt when it is created.
- Fix `#[AsSdcComponent]` on Symfony 6.4 and 7.0, where a class carrying only it
  was never registered as a component.
- Fix the `Link` preload header depending on listener registration order: the
  response listener runs at priority 64, before WebLink.
- Fix the injection of assets: only into HTML responses, only at the first of
  several placeholders (each used to get a copy, so scripts ran twice), with
  `Content-Length` updated; attributes follow the Twig component convention
  (`false` and `null` leave them out, `['async' => false]` used to render
  `async=""`), URLs are escaped, and an empty `placeholder` is rejected.
- Fix a template named explicitly being replaced by a `.html.twig` next to the
  component class.
- Fix the co-located template of a component under a namespaced Twig path being
  named without its namespace (*Unable to find template*), and the runtime pipeline
  not finding co-located files under the application's `twig.paths`.
- Fix the page controller of a component — such as the `HomepageAction` of
  `--action` — not getting the component namespace without LiveComponent
  (`kernel.controller`).
- Fix the page template of `--action` being taken for an anonymous component.
- Fix `make:sdc-component` writing the licence header of the bundle into the
  generated `.css` and `_controller.js`, and make it say that
  `config/routes/ux_sdc.yaml` loads the route when the file exists.
- Fix `cache:clear` / `cache:warmup` compiling the PHP skeletons of
  `make:sdc-component` as Twig templates in every application without
  `twig.file_name_pattern` (dozens of Twig deprecations, and two templates that do
  not compile): TwigBundle registers the bundle's `templates/` as `@Sdc`, so the
  skeletons moved to `skeleton/`.

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

[1.0.0]: https://github.com/tito10047/ux-sdc/compare/0.4.0...1.0.0
[0.4.0]: https://github.com/tito10047/ux-sdc/compare/0.3.1...0.4.0
[0.3.0]: https://github.com/tito10047/ux-sdc/compare/0.2.0...0.3.0
