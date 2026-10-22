# Diagnostics

Components degrade gracefully: a missing asset never breaks a page. To see what
each component gets, and what keeps any of it from loading, ask the bundle.

## `debug:sdc`

```bash
php bin/console debug:sdc
```

```
 ---------------- --------------------------------------- -------------------------- ------------------- ---------------------------------- ---------------------
  Component        Template                                CSS                        JS                  Preload                            Stimulus controller
 ---------------- --------------------------------------- -------------------------- ------------------- ---------------------------------- ---------------------
  UI:Alert:Alert   UI/Alert/Alert.html.twig (co-located)   UI/Alert/Alert.css         UI/Alert/Alert.js   UI/Alert/Inter.woff2 (#[Asset])    UI--Alert--Alert
  ⚠ Legacy:Old     ⚠ /Legacy/Old.html.twig (default)       ⚠ css/old.css (#[Asset])   -                   -                                  -
 ---------------- --------------------------------------- -------------------------- ------------------- ---------------------------------- ---------------------

Legacy:Old (App\Component\Legacy\Old)
-------------------------------------

 * The component has no template: none next to the class can be used, and "/Legacy/Old.html.twig", which Twig Components looks up by default, does not exist. […]
 * The asset "css/old.css" (#[Asset]) is not in the asset map, so the page links to it as it is and it will most likely not load. […]
```

It flags (⚠) a file next to a class that is outside every Twig or AssetMapper
path, an asset path the asset map does not know, a missing template, and a
template that uses `{{ controller }}` when StimulusBundle has no controller of
that name (without StimulusBundle the controller is shown as not checked).
Preloaded fonts and images have a column of their own. It reads the metadata from
where the pages do — the build-time cache with `metadata_cache: true` — so it
also shows a cache that predates a file you just added.

- `debug:sdc Alert` — only the components whose name or class contains `Alert`
  (any case); a single match is shown in detail: public paths, where each asset
  was declared, the routes of the controllers next to the class. No match exits
  with 1.
- `--format=json` — the same, for scripts and tools (see below).
- `--strict` — exits with 1 when there is a problem, for a CI step. Without it,
  like every `debug:*` command, it exits with 0. A later release may add checks,
  so `--strict` may find problems it did not find before.

Anonymous components are listed too, marked `(anonymous)`. So are the components
of bundles, such as `Turbo:Frame` of symfony/ux-turbo, as `debug:twig-component`
lists them; give a name to see only some. A component that Twig Components
renders with a service instead of a template, such as `UX:Icon` of
symfony/ux-icons, shows that service, marked `(renderer)`, and has no template
to miss. `debug:twig-component` complements the command with the props and hooks
of a component.

### JSON output

```json
{
    "metadata_cache": true,
    "components": [
        {
            "name": "UI:Alert:Alert",
            "class": "App\\Component\\UI\\Alert\\Alert",
            "template": {"name": "UI/Alert/Alert.html.twig", "origin": "co-located", "found": true},
            "assets": [
                {"path": "UI/Alert/Alert.css", "public_path": "/assets/UI/Alert/Alert-9c2e1d0.css", "type": "css", "source": "discovered", "priority": 0, "attributes": {}, "mapped": true}
            ],
            "stimulus_controller": {"name": "UI--Alert--Alert", "set_by_component": false, "used": true, "registered": true},
            "routes": [],
            "problems": []
        }
    ]
}
```

- `metadata_cache` — whether the metadata comes from the build-time cache.
- `class` — `null` for an anonymous component.
- `template.origin` — `co-located`, `explicit` (named by the registration),
  `method` (`template_from_method`; `name` is the method), `default` (the one
  Twig Components looks up), `anonymous` or `renderer` (no template: a service
  tagged `ux.twig_component.twig_renderer` renders the component; `name` is its
  class); `found` is `null` when the template is only known on render, and for
  `renderer`.
- `assets[].type` — `css`, `js`, `font` or `image`; `source` — `discovered`,
  `#[Asset]` or `#[AsSdcComponent]`; `public_path` — `null` when the asset map does
  not know the path; `mapped` — `null` for a URL, linked as it is.
- `stimulus_controller` — the `controller` variable: its derived `name` (`null`
  outside `component_namespace`, for an anonymous component, or when the component
  sets it itself, `set_by_component`), whether the template `used` it, and whether
  StimulusBundle `registered` a controller of that name (`null` without
  StimulusBundle).
- `routes` — `{name, path}` of the controllers in the namespace of the class.
- `problems` — one message per problem, as in the text output.

## Logs

The bundle logs what goes wrong while rendering, through the application's
`logger` (on the `ux_sdc` channel with MonologBundle):

| Level | When | Logged |
|-------|------|--------|
| warning | A co-located template is outside every Twig path, or a co-located CSS/JS file outside every AssetMapper path, so it is left out (see [Where components can live](components.md#where-components-can-live)). | in debug only; at build time (`metadata_cache: true`) into the container compiler log, shown in the profiler's *Logs* panel under *Container* |
| warning | An HTML page with a `<head>` renders components with assets but does not call `render_component_assets()`. Fragments without a `<head>` (partials) and Live Component and Turbo responses are not reported. | in debug only |
| warning | The `X-SDC-Assets` header of a fragment is longer than 2 KB, which a reverse proxy may reject (see [the header](live-and-turbo.md#the-x-sdc-assets-header)). | in debug only |
| warning | The page has its import map after `render_component_assets()`, so component modules come before it. | in debug only |
| warning | A component asset path is not in the asset map (a typo in `#[Asset]`, a file AssetMapper does not serve), so it is linked as it is. URLs (`https://…`, `//…`, `/…`) are linked as they are on purpose and not reported. | in debug only |
| error | `metadata_cache` is on but `%kernel.cache_dir%/sdc_metadata.php` does not exist — the cache was not warmed where the application runs, or the runtime cache directory differs from the build one. Pages still render, without component CSS and JS. | always |

Every message says what to change.

## Web profiler

With WebProfilerBundle (or just the FrameworkBundle profiler) in a debug
environment, the *UX SDC* panel shows what one request did:

- every component it rendered — name, class (or *anonymous component*), template,
  how many times — with the assets each asked for and where each was declared
  (`discovered`, `#[Asset]`, `#[AsSdcComponent]`);
- the assets of the response and how they got there: tags at the placeholder
  (with or without a `Link: preload`), a preload tag for a font or an image, an
  `X-SDC-Assets` entry for a Live Component, Turbo or partial response — or
  nothing, for a response that is not HTML, a streamed response or a
  sub-request (whose components are listed with the main request);
- which component asked for each asset, so an asset asked for by several renders
  shows up once, with all of them;
- the problems of that request: a page without the placeholder, an import map
  after the component modules, an asset path the asset map does not know, an
  asset of a type the bundle does not load, an `X-SDC-Assets` header too long for
  a proxy, the files next to a rendered component that had to be left out, and a
  component with assets rendered after the response got them (in the body of a
  streamed response).

The toolbar shows the number of components and loaded assets, in yellow when
there is a problem. The panel exists only in a debug container with the
profiler enabled; anywhere else nothing is recorded.
