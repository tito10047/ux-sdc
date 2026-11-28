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
the files next to each component class (or template, for an anonymous component),
and injects the `<link>` and `<script>` tags of the components a response actually
rendered — into the page, or for Live Components and Turbo fragments, through a
response header the bundle's Stimulus controller reads.

The idea comes from Hugo Alliaume's article
[*A Better Architecture for Your Symfony UX Twig Components*](https://hugo.alliau.me/blog/posts/a-better-architecture-for-your-symfony-ux-twig-components).
It is used in production on [mostka.sk](https://mostka.sk/),
[formalitka.mostka.sk](https://formalitka.mostka.sk/) and [ycon.cc](https://ycon.cc).

## Requirements

PHP 8.1+, Symfony 6.4, 7.x or 8.x, `symfony/ux-twig-component` 2.20+ or 3.x, and
AssetMapper (Webpack Encore is not supported). StimulusBundle, LiveComponent and
MakerBundle are optional. See [Installation](docs/installation.md#requirements).

## Installation

```bash
composer require tito10047/ux-sdc
```

A [Flex recipe](docs/installation.md#with-the-flex-recipe) is proposed to
`symfony/recipes-contrib`. It configures the bundle, imports its routes and adds
`{{ render_component_assets() }}` to `templates/base.html.twig`; what is left to
you — the Composer PSR-4 entry, and for Live Components or Turbo the loader
controller on `<body>` — it prints. Until it is available, or without Flex,
follow [Without the recipe](docs/installation.md#without-the-recipe): five short
steps.

## Quick start

With `App\Component\` mapped to `src_component/` and the bundle configured as
above, `src_component/Alert/Alert.php`:

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

A page that renders an Alert gets
`<link rel="stylesheet" href="/assets/Alert/Alert-….css" data-turbo-track="dynamic">`
in its `<head>` and a `Link: </assets/Alert/Alert-….css>; rel="preload"; as="style"`
header. A page without an Alert gets neither.

`php bin/console make:sdc-component Alert` generates such a skeleton, and
`php bin/console debug:sdc` shows what each component gets.

## Documentation

- [Installation](docs/installation.md) — requirements, the Flex recipe, manual setup
- [Components](docs/components.md) — names, co-located files, templates, Stimulus
  controllers, where components can live, anonymous components
- [Assets](docs/assets.md) — `#[AsSdcComponent]`, `#[Asset]`, ES modules, font and
  image preloads, CSP nonces
- [Live Components and Turbo](docs/live-and-turbo.md) — the loader controller and
  the `X-SDC-Assets` header
- [Generating components](docs/maker.md) — `make:sdc-component`, page components
  with `--action`
- [Configuration](docs/configuration.md) — every option
- [Diagnostics](docs/diagnostics.md) — `debug:sdc`, logs, the web profiler panel
- [How it works](docs/how-it-works.md) — the build-time and runtime pipelines,
  performance
- [Known limitations and non-goals](docs/known-limitations.md)
- [Upgrading from 0.4 to 1.0](UPGRADE.md) and the [changelog](CHANGELOG.md)

[tito10047/ux-sdc-skills](https://github.com/tito10047/ux-sdc-skills) teaches
coding agents how components are discovered, how their assets reach the browser
and how to diagnose the failure modes. In Claude Code:
`/plugin marketplace add tito10047/ux-sdc-skills`, then
`/plugin install ux-sdc-skills`. For other agents it is a plain directory of
Markdown in the [agentskills.io](https://agentskills.io) layout, with `AGENTS.md`,
`CLAUDE.md` and `llms.txt`.

## Backward compatibility

The bundle follows [Semantic Versioning](https://semver.org/). From 1.0, a minor
or patch release does not break:

- **Configuration:** the `ux_sdc` options, their defaults and accepted values
  ([Configuration](docs/configuration.md)), and the `ux_sdc.ux_components_dir`
  parameter.
- **PHP:** `Attribute\AsSdcComponent` and `Attribute\Asset` (with its `CSS`, `JS`,
  `FONT` and `IMAGE` constants), the `Twig\Stimulus` trait,
  `Twig\ComponentNamespaceInterface`, `Csp\NonceProviderInterface`,
  `Csp\NelmioNonceProvider` as a value of `csp_nonce_provider`, and `SdcBundle`
  with `SdcBundle::STIMULUS_CONTROLLER` — all in `Tito10047\UX\Sdc`.
- **Twig:** the `render_component_assets()` function, the `sdc_loader_controller`
  global, the `controller` variable and how its value is derived, how components
  and their co-located files are named.
- **HTTP:** the `X-SDC-Assets` header, format version 1, and the loader Stimulus
  controller that reads it.
- **Routing:** the `type: ux_sdc` route import.
- **Logging:** the `ux_sdc` log channel.
- **Console:** the names, arguments and options of `debug:sdc` and
  `make:sdc-component`, the exit codes and the JSON structure of `debug:sdc`.

Not covered, and free to change in any release: every class, method and constant
marked `@internal` (all of `src/` not listed above), service IDs, other container
parameters, the format of the metadata cache, the text output of `debug:sdc`, the
wording of log messages and the code `make:sdc-component` generates. A minor
release may add `debug:sdc` checks, so `--strict` may find problems it did not
find before. Something covered is deprecated in a minor release, with a deprecation
notice where possible, and removed in the next major one.

## Contributing

Bug reports and pull requests are welcome on
[GitHub](https://github.com/tito10047/ux-sdc/issues). Run `./vendor/bin/phpunit`,
`./vendor/bin/phpstan analyse` and `./vendor/bin/php-cs-fixer fix --dry-run`
before opening a pull request.

## License

MIT — see [LICENSE.md](LICENSE.md).
