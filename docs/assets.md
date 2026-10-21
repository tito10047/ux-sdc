# Assets

A component's stylesheets and scripts reach the page only when the component is
rendered on it. They come from two places: the files
[discovered next to the class](components.md#co-located-files) (or the template,
for an [anonymous component](components.md#anonymous-components)), and the ones
[declared](#declaring-assets) with `#[AsSdcComponent]` and `#[Asset]`.

## What a page gets

`{{ render_component_assets() }}` in the `<head>` of the layout
([Installation](installation.md#without-the-recipe), step 4) prints a placeholder.
When the response is sent, the placeholder is replaced with the tags of the
components rendered on the page, sorted by priority. On a page that renders an
`Alert:Alert` with `Alert.css` and `Alert.js` next to its class, the `<head>` gets

```html
<link rel="stylesheet" href="/assets/Alert/Alert-9c2e1d0.css" data-turbo-track="dynamic">
<script src="/assets/Alert/Alert-3b1f2a4.js" type="module"></script>
```

(see [Turbo](live-and-turbo.md#turbo) for `data-turbo-track`) and the response a
`Link: </assets/Alert/Alert-9c2e1d0.css>; rel="preload"; as="style"` header. A
page without an Alert gets neither. An asset is loaded once, however many
components ask for it.

A response the browser inserts only a part of — a Live Component re-render, a
Turbo Frame or Stream, a partial — gets the
[`X-SDC-Assets` header](live-and-turbo.md#the-x-sdc-assets-header) instead.

## Declaring assets

### `#[AsSdcComponent]`

`#[AsSdcComponent]` is `#[AsTwigComponent]` with two extra arguments, for a
stylesheet and a script that do not follow the naming convention. They are loaded
in addition to the discovered files:

```php
#[AsSdcComponent(css: 'Alert/alert.theme.css', js: 'Alert/alert.legacy.js')]
final class Alert {}
```

### `#[Asset]`

`#[Asset]` adds any other stylesheet, script, font or image, from any
AssetMapper path or an absolute URL. It is repeatable and works on every
component class:

```php
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Tito10047\UX\Sdc\Attribute\Asset;

#[AsTwigComponent]
#[Asset('styles/reset.css', priority: 10)]
#[Asset('https://cdn.example.com/chart.umd.js', type: 'js', attributes: ['type' => false, 'defer' => true])]
final class Chart {}
```

| Argument | Meaning |
|----------|---------|
| `path` | AssetMapper logical path (`styles/reset.css`) or a URL. Required — `#[Asset]` without a path is ignored. |
| `type` | `css`, `js`, `font` or `image` (`Asset::CSS`, `Asset::JS`, `Asset::FONT`, `Asset::IMAGE`). When omitted, the extension of the path tells it: `.css`; `.js`, `.mjs`; `.woff2`, `.woff`, `.ttf`, `.otf`; `.avif`, `.webp`, `.png`, `.jpg`, `.jpeg`, `.gif`, `.svg` (the query string of a URL does not count). A path that tells none — `https://fonts.googleapis.com/css2?family=Inter`, a URL without an extension — has to declare it; an unknown type, or a type that cannot be told, fails the container build (or, with `metadata_cache: false`, the render) with the name of the component. |
| `priority` | Higher comes first in `<head>`. Default `0`. |
| `attributes` | Extra HTML attributes for the tag. `true` renders a bare attribute, `false` and `null` leave it out. They override the ones the bundle sets: `'type' => false` loads a classic script (see below), `'nonce' => false` leaves the [CSP nonce](#content-security-policy-nonces) out, `'data-turbo-track' => false` the [Turbo mark](live-and-turbo.md#turbo) of a stylesheet, `'crossorigin' => false` the `crossorigin` of a [font preload](#fonts-and-images). |

`#[Asset]` is for files that are *not* discovered. It does not switch discovery on
or off; `auto_discovery` does.

## Scripts are ES modules

AssetMapper serves JavaScript as ES modules, so every component script — discovered,
from `#[AsSdcComponent(js: …)]` or from `#[Asset]` — is loaded as one:

```html
<script src="/assets/Alert/Alert-3b1f2a4.js" type="module"></script>
```

A component script can therefore `import` what the application's import map
provides (`import { Controller } from '@hotwired/stimulus'`) and files next to it
(`import './format.js'`). Like every module, it runs once the page is parsed, in
strict mode, with its own scope: a top-level `var` or `function` is not a global.

The import map must come first. A browser that does not merge import maps ignores
one that comes after a module script, and then the bare imports of the whole
page fail — the application's own entrypoint included. Keep
`{{ render_component_assets() }}` after `{{ importmap('app') }}`; in debug the
bundle logs a warning when it is not.

A script that is not a module (a UMD build from a CDN, a file that relies on its
top-level variables being global) opts out with the `type` attribute:

```php
#[Asset('https://cdn.example.com/chart.umd.js', attributes: ['type' => false])]
```

`'type' => false` leaves the attribute out, which is a classic script; any other
value is used as it is. Do not reach for `nomodule` instead: browsers that support
modules do not run a `nomodule` script at all.

Stylesheets and classic scripts are announced early with a
`Link: <…>; rel="preload"` response header. Module scripts are not:
`rel="preload"; as="script"` would make the browser download them twice, and
`rel="modulepreload"` in a header starts loading modules before the import map
is parsed. The browser fetches a module as soon as it parses its tag.

## Fonts and images

A font or an image is not loaded by a tag of its own — the component's stylesheet
(`@font-face`, `background-image`) or template (`<img>`) uses it — so `#[Asset]`
only *preloads* it, and the browser fetches it before it gets to that use:

```php
#[AsSdcComponent]
#[Asset('Hero/Inter.woff2')]
#[Asset('Hero/hero.avif', attributes: ['fetchpriority' => 'high'])]
final class Hero {}
```

```html
<link rel="preload" href="/assets/Hero/Inter-5c1d0e2.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/Hero/hero-a01b2c3.avif" as="image" type="image/avif" fetchpriority="high">
```

and the same in the `Link` response header:

```
Link: </assets/Hero/Inter-5c1d0e2.woff2>; rel="preload"; as="font"; type="font/woff2"; crossorigin,</assets/Hero/hero-a01b2c3.avif>; rel="preload"; as="image"; type="image/avif"; fetchpriority="high"
```

The header lets the browser start before it parses the page; the tag is there
because a proxy, a CDN or an HTML cache may drop the header.

- `type` is the MIME type of the extension, so that a browser skips a format it
  does not support. A URL without an extension gets none; set it with
  `attributes: ['type' => 'font/woff2']`.
- A font gets `crossorigin`: browsers fetch fonts in CORS mode, same-origin ones
  included, and a preload made without it is not reused — the font would be
  downloaded twice. A font from another origin needs CORS headers there anyway.
- Preload only what the page uses right away: a browser warns about a preload
  left unused for a few seconds after the page loaded. For an image, a responsive
  `<img>` needs `imagesrcset`/`imagesizes` in `attributes` to match the
  candidate the browser will pick.
- A preload takes no nonce: `font-src` and `img-src` govern it.
- A [fragment](live-and-turbo.md#the-x-sdc-assets-header) does not list fonts and
  images: it inserts the stylesheet or markup that uses them as soon as it arrives,
  so a preload would come no earlier.

Within the CSS of the component, refer to the font relatively
(`src: url('./Inter.woff2')`): AssetMapper rewrites the URL to the same public path
the preload uses.

## Content Security Policy nonces

Under a policy with nonces (`script-src 'nonce-…'`), the injected tags need the
nonce of the response. Point `csp_nonce_provider` at a service that supplies it.
With [NelmioSecurityBundle](https://github.com/nelmio/NelmioSecurityBundle) and
its `csp` feature on, the bundle ships one:

```yaml
# config/packages/ux_sdc.yaml
ux_sdc:
    csp_nonce_provider: Tito10047\UX\Sdc\Csp\NelmioNonceProvider
```

```html
<link rel="stylesheet" href="/assets/Alert/Alert-9c2e1d0.css" nonce="Xr2Lq…" data-turbo-track="dynamic">
<script src="/assets/Alert/Alert-3b1f2a4.js" type="module" nonce="Xr2Lq…"></script>
```

Nelmio adds a nonce to the policy when it is asked for one, which is why this is
not automatic: once a nonce is in `script-src`, browsers ignore `'unsafe-inline'`,
and an application relying on it would have its inline scripts blocked on every
page that renders component JS. The bundle asks only for the nonces of the tags it
injects — no script nonce on a page without component scripts.

Pass the same nonce to the import map, which needs one too:
`{{ importmap('app', {nonce: csp_nonce('script')}) }}`.

For any other source of nonces, implement
`Tito10047\UX\Sdc\Csp\NonceProviderInterface` (`getScriptNonce(): ?string`,
`getStyleNonce(): ?string`) and give its service ID. It is called while the
response is sent, once per kind of tag; return the nonce the response's
`Content-Security-Policy` header allows, or `null` for none. A provider that keeps
the nonce in a property has to forget it between requests (`kernel.reset`), or a
long-running worker would reuse it.

The assets of a Live re-render or a Turbo fragment get the nonce of the page, see
[Live Components and Turbo](live-and-turbo.md).
