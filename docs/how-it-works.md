# How it works

1. **Container build.** The bundle registers `ux_components_dir` as a Twig path,
   an AssetMapper path (minus its templates) and a Stimulus controller path, and
   adds a `twig_component.defaults` entry for `component_namespace`. Every class
   under `component_namespace` is registered as an autoconfigured service, so
   `#[AsSdcComponent]`, `#[AsTwigComponent]`, `#[AsLiveComponent]` and `#[Route]`
   on it take effect. A compiler pass collects the application's Twig and
   AssetMapper paths and the templates of anonymous components; with
   `metadata_cache` on, it resolves the template and assets of every
   `twig.component` service and the assets of every anonymous component, and
   writes them to `%kernel.cache_dir%/sdc_metadata.php`. AssetMapper's import map
   configuration reader gets the module scripts of every component as entries,
   in addition to those of `importmap.php`, so that the files they import resolve
   ([Imports in a component script](assets.md#imports-in-a-component-script)).
2. **Rendering.** On TwigComponent's `PostMountEvent` and `PreRenderEvent`, a
   listener adds the component's assets to a per-request registry, points the
   component at its co-located template unless one was named explicitly, and sets
   the `controller` variable; for an anonymous component, it adds the assets next
   to its template. With `metadata_cache` on it reads the cached file; off, it
   resolves the files next to the class or template (once per class or template
   and process).
3. **Response.** A `kernel.response` listener (priority 64, ahead of WebLink's
   `Link` header listener) replaces the first placeholder in an HTML main response
   with the tags, sorted by priority — scripts as ES modules, with the CSP nonce
   when a provider is configured, fonts and images as `<link rel="preload">` — and
   adds a `Link: rel="preload"` header for each stylesheet, classic script, font
   and image. A Live Component re-render, a Turbo Frame or Stream, and any other
   HTML response without the placeholder get the
   [`X-SDC-Assets` header](live-and-turbo.md#the-x-sdc-assets-header) instead;
   a redirect and a response without a body get neither. The registry is reset
   between requests, so long-running workers do not carry assets over, and when a
   request fails ([error pages](#error-pages)).

Components rendered in a sub-request (`render(controller(...))`) end up in the
main response.

## Error pages

When the main request fails — a component throws while the page renders, say —
the bundle clears the registry on `kernel.exception` (priority 128), before
Symfony's `ErrorListener` (-128) renders the error page in a sub-request and makes
its response the main response. The error page gets exactly the assets of its own
components:

- An `error.html.twig` that extends your layout, with
  `{{ render_component_assets() }}` and components, gets their tags, like any page.
- Symfony's own error page and the exception page of debug have neither: no tags,
  no header.
- A JSON error is not HTML: nothing.

An exception listener of your own that sets a response at a priority above 128
stops the event before the bundle sees it; its response gets the assets of the
page that failed. Only the main request clears the registry: an error rendered
inside the page, by a sub-request, leaves the page's assets alone. The profiler
panel lists the components that rendered before the failure and the assets it
discarded.

## Performance

Measured with 500 components (details and methodology in
[benchmark.md](../benchmark.md)):

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
