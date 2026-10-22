# Live Components and Turbo

A full page gets its component assets as tags at the placeholder (see
[Assets](assets.md#what-a-page-gets)). A response the browser inserts only a part
of cannot: Live Components keep only the component's markup, Turbo only the frame
or the streams. Such a response lists the assets in the
[`X-SDC-Assets` header](#the-x-sdc-assets-header) instead, and a Stimulus
controller shipped with the bundle, the *loader*, adds the missing ones to
`<head>`.

## The loader controller

Attach it once, to `<body>`:

```twig
<body {{ stimulus_controller(sdc_loader_controller) }}>
```

Put it on `<body>` rather than an inner element: it has to connect before the
Live Components it watches. It is only needed for Live Components, Turbo Frames
and Turbo Streams; it depends on nothing but Stimulus, and does nothing without
them. A fragment your own script fetches is for that script to handle, see
[below](#the-x-sdc-assets-header).

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

The loader gives the assets it inserts the CSP nonce it finds on the page's own
`<script>` and `<link>`/`<style>` elements — the policy that applies is the one
the page was loaded with — and, for scripts, Turbo's
`<meta name="csp-nonce">` when no script of the page has one.

## Live Components

Live Components get their assets like any other component on the first render.
When a re-render brings a component that is not on the page yet, the loader adds
its assets from the header of the re-render.

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

## Turbo

Turbo Drive loads every page with `fetch()` and merges its `<head>` into the
current one, so component assets need nothing special: keep
`{{ render_component_assets() }}` in `<head>`, and Turbo adds the stylesheets and
scripts of the next page. Component stylesheets carry
`data-turbo-track="dynamic"`, which makes Turbo (8.0+) also remove the ones the next
page does not render on navigation — without it every stylesheet a visit ever
loaded would stay, and a component's global rule (`body { overflow: hidden }` in a
modal) would go on applying to the pages after it. An asset opts out with
`attributes: ['data-turbo-track' => false]`. Never mark component assets
`"reload"`: the set changes from page to page, and every such change would be a
full reload. Scripts get no mark — Turbo never removes a script; a module that
was already loaded is not run again.

A Turbo Frame or Stream keeps only the frame or the streams of the response, so
tags in its `<head>` would be lost. Those responses get the
[`X-SDC-Assets` header](#the-x-sdc-assets-header) instead — even a whole page
with the placeholder, which is what a frame request is often answered with — and
the [loader controller](#the-loader-controller) adds what the page is missing on
`turbo:before-fetch-response`, before Turbo renders the frame or the streams.

## The X-SDC-Assets header

The listener decides per main HTML response (a Content-Type with `html`, or none
and an `html`-ish request format):

| Response | Assets |
|----------|--------|
| Live Component re-render (`Accept: application/vnd.live-component+html`, or a response of that type) | header |
| Turbo Frame request (`Turbo-Frame` request header) | header, even when the page has the placeholder |
| Turbo Stream (`text/vnd.turbo-stream.html` response, or the `turbo_stream` request format) | header |
| Any other response with the placeholder — a page, a Turbo Drive visit | tags |
| Any other response without it — an htmx partial, a component fetched by your own script | header |

Sub-requests, non-HTML and streamed responses get neither, and neither does a
response that rendered no component with assets. Nor does a response the client
does not show: a redirect (a 3xx with a `Location`), a 204 or 304, an empty body.
A HEAD request is answered like the GET request, headers included; Symfony drops
the body afterwards.

A request that fails throws away the assets collected so far, on
`kernel.exception`: the error response — the exception page, your
`error.html.twig`, the response of your own exception listener — gets the assets
of the components it renders itself, in tags or in the header by the rules above,
and never those of the page that failed. See
[How it works](how-it-works.md#error-pages).

The value is JSON, ASCII-only (anything else is a `\u` escape), with the assets
in the order of the tags of a full page:

```
X-SDC-Assets: {"v":1,"assets":[{"type":"css","href":"/assets/Alert/Alert-9c2e1d0.css","attributes":{"data-turbo-track":"dynamic"}},{"type":"module","href":"/assets/Alert/Alert-3b1f2a4.js"},{"type":"script","href":"https://cdn.example.com/chart.umd.js","attributes":{"defer":true}}]}
```

- `v` — the version of the format, `1`. It changes when an older client would
  misread the value; a client ignores a version it does not know.
- `type` — `css` (stylesheet), `module` (module script) or `script` (classic
  script). A client skips an entry of a type it does not know, so a type may be
  added without a new version. Fonts and images are not listed (see
  [Fonts and images](assets.md#fonts-and-images)).
- `href` — the public path or URL, as in the `href`/`src` of the tag.
- `attributes` — left out when empty: the attributes the tag of a full page
  gets after `rel`/`href`/`src` and a module's `type="module"`, in that order — a
  string, or `true` for a bare attribute. The CSP nonce is not among them: the
  client uses the nonce of the page, unless the asset sets its own (`"nonce": "…"`)
  or none (`"nonce": false`).

The loader resolves `href` against the document, skips an asset the document
already has (any `<link rel="stylesheet">` or `<script src>` with the same URL),
and builds the others attribute by attribute in that order, so that Turbo, which
compares `<head>` elements by their markup, takes them for the tags of a full page.
Read the header from your own script the same way when you insert fragments
yourself.

The header lists the assets of the components rendered for that response, which
for a fragment is usually a handful — about 60–100 bytes each. A reverse proxy
fails a response whose headers do not fit its buffer: nginx in front of PHP-FPM
answers *502 upstream sent too big header* past `fastcgi_buffer_size` (4k or 8k by
default, for all the headers together). In debug the bundle logs a warning when the
header alone is longer than 2 KB; raise the buffer
(`fastcgi_buffer_size 16k;`, `proxy_buffer_size` behind `proxy_pass`) if a fragment
legitimately renders that many components.

Fragments render before their stylesheets load, and Turbo Streams over WebSocket or
SSE carry no headers at all; see [Known limitations](known-limitations.md).
