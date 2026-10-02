# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
