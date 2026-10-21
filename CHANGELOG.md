# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `ux_sdc.exclude_templates_from_asset_map` (default `true`): keeps the Twig
  templates of `ux_components_dir` out of the AssetMapper. Set it to `false`
  only if you really want them mapped.

### Fixed

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
