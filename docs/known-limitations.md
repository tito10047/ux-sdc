# Known limitations and non-goals

## Known limitations

- **Component controller names keep their capitals.** Stimulus does not call
  `…ValueChanged()` callbacks of such a controller and needs bracket access for
  its outlets; see
  [Capital letters in controller names](components.md#capital-letters-in-controller-names).
- **Fragments render before their stylesheets load.** The loader adds the
  stylesheets of a Live re-render or a Turbo Frame/Stream as the response arrives,
  but neither Live Components nor Turbo wait for them, so a component that is new
  to the page may show unstyled for a moment.
- **Turbo Streams over WebSocket or SSE** (Mercure, `<turbo-stream-source>`) come
  without HTTP headers: the assets of a component that first appears through one
  are not loaded unless the page already has them.
- **Streamed responses.** Components rendered while a `StreamedResponse` is being
  sent are registered after `kernel.response` and get no assets.
- **ESI.** Each ESI fragment is its own main request; assets of its components do
  not reach the page that includes it.
- **New asset files without a rebuild.** With `metadata_cache: true`, adding or
  removing a CSS/JS/Twig file next to a component, or an anonymous component,
  needs a `cache:clear`. With `false`, a long-running worker picks it up after a
  restart; `debug:sdc` lists an anonymous component added since the container was
  built only once it is rebuilt.
- **`UX:Icon` with Twig Components 2.20.** symfony/ux-icons 2.20 renders it from
  an event listener, which `debug:sdc` cannot see, so it reports the component's
  template as missing. From Twig Components 2.21 on, ux-icons registers a renderer
  and `debug:sdc` shows it.

## Non-goals

- **SCSS, TypeScript or any other compilation.** Compiling assets is AssetMapper's
  job (or that of a dedicated bundle such as `symfonycasts/sass-bundle`). Compile
  `Alert.scss` to `Alert.css` next to the class and discovery picks it up.
- **Bundling.** The bundle is a thin layer between TwigComponent and AssetMapper
  and does not build its own asset pipeline. It only lists the component scripts
  in AssetMapper's import map, so that their relative imports resolve
  ([Imports in a component script](assets.md#imports-in-a-component-script)), and
  leaves `importmap.php` to the application.
- **Webpack Encore.** Component assets are served through AssetMapper only.
