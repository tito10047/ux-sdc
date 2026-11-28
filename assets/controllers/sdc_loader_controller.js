// Loads the CSS/JS of SDC components that first appear on the page through a
// response the page inserts only a part of: a Live Component re-render, a Turbo
// Frame, Turbo Streams.
//
// On a full page load the server injects component assets into the HTML. A
// fragment cannot carry them that way — the client drops everything but the
// component, the frame or the streams — so the server lists them in the
// `X-SDC-Assets` response header, and this controller adds the ones the
// document does not have yet. The header is JSON:
//
//   {"v":1,"assets":[
//     {"type":"css","href":"/assets/Alert-1b2c.css","attributes":{"data-turbo-track":"dynamic"}},
//     {"type":"module","href":"/assets/Alert-3d4e.js"},
//     {"type":"script","href":"https://cdn.example.com/lib.js","attributes":{"defer":true}}
//   ]}
//
// `css` is a stylesheet, `module` a module script, `script` a classic one, in
// the order of the tags of a full page; an entry of any other type is skipped,
// so a type can be added without raising the version. `attributes` are the
// further attributes of the tag (a string, or `true` for a bare attribute).
// The elements are built like the tags of a full page, attribute by attribute,
// so that Turbo Drive, which compares <head> elements by their markup, sees
// them as the same.
//
// Under a Content Security Policy with nonces, the new elements get the nonce of
// the page they are added to, taken from an element that already carries one,
// unless the entry sets its own `nonce` (or `"nonce": false`).
//
// The controller is registered automatically through the bundle's
// `assets/package.json`; attach it once per page, ideally on <body>:
//
//     <body {{ stimulus_controller(sdc_loader_controller) }}>
//
// It deliberately has no dependency on `@symfony/ux-live-component` or
// `@hotwired/turbo`: it is registered in every application, with or without
// them. It only reacts to their DOM events — `live:connect` / `live:disconnect`
// and `turbo:before-fetch-response` — which simply never fire when they are
// not installed.

import { Controller } from '@hotwired/stimulus';

const ASSETS_HEADER = 'X-SDC-Assets';
// The version of the header format this controller reads; the server raises it
// on any change an older reader would misread.
const PROTOCOL_VERSION = 1;
const ASSET_TYPES = ['css', 'module', 'script'];

export default class extends Controller {
  initialize() {
    this.onLiveConnect = this.onLiveConnect.bind(this);
    this.onLiveDisconnect = this.onLiveDisconnect.bind(this);
    this.onRenderStarted = this.onRenderStarted.bind(this);
    this.onTurboFetchResponse = this.onTurboFetchResponse.bind(this);
    // Live components we have hooked into. Kept so every hook can be removed
    // again, and so a component that reconnects is not hooked twice.
    this.components = new Set();
  }

  connect() {
    // Capture phase on the document: the events bubble from the component or
    // frame element, so this works wherever on the page the controller is attached.
    document.addEventListener('live:connect', this.onLiveConnect, true);
    document.addEventListener('live:disconnect', this.onLiveDisconnect, true);
    document.addEventListener('turbo:before-fetch-response', this.onTurboFetchResponse, true);
  }

  disconnect() {
    document.removeEventListener('live:connect', this.onLiveConnect, true);
    document.removeEventListener('live:disconnect', this.onLiveDisconnect, true);
    document.removeEventListener('turbo:before-fetch-response', this.onTurboFetchResponse, true);

    for (const component of this.components) {
      component.off('render:started', this.onRenderStarted);
    }
    this.components.clear();
  }

  onLiveConnect(event) {
    const component = event.detail?.component;
    if (!component || typeof component.on !== 'function' || this.components.has(component)) {
      return;
    }

    // `render:started` runs after the response arrived but before the new HTML
    // is morphed into the page, so styles can start loading as early as possible.
    component.on('render:started', this.onRenderStarted);
    this.components.add(component);
  }

  onLiveDisconnect(event) {
    const component = event.detail?.component;
    if (!component || !this.components.has(component)) {
      return;
    }

    component.off('render:started', this.onRenderStarted);
    this.components.delete(component);
  }

  onRenderStarted(html, backendResponse) {
    applyAssets(backendResponse?.response?.headers);
  }

  // Every response Turbo fetches — frame, stream, Drive visit, prefetch —
  // before it renders it. A page with the placeholder comes without the header.
  onTurboFetchResponse(event) {
    applyAssets(event.detail?.fetchResponse?.response?.headers);
  }
}

/**
 * Adds the assets listed in the `X-SDC-Assets` header that the document does
 * not have yet, in the order of the header. Does nothing without the header.
 */
function applyAssets(headers) {
  if (!headers || typeof headers.get !== 'function') {
    return;
  }

  const assets = parseAssetHeader(headers.get(ASSETS_HEADER));
  if (assets.length === 0) {
    return;
  }

  const styleNonce = documentNonce('link[nonce], style[nonce]');
  const scriptNonce = documentNonce('script[nonce]') || turboNonce();

  for (const asset of assets) {
    if (asset.type === 'css') {
      if (!hasStylesheet(asset.url)) {
        document.head.appendChild(createStylesheet(asset, styleNonce));
      }
    } else if (!hasScript(asset.url)) {
      document.head.appendChild(createScript(asset, scriptNonce));
    }
  }
}

/**
 * The entries of the header, each with the absolute `url` its `href` resolves
 * to; entries that are not understood, and any URL listed before, are dropped.
 * Returns an empty list for a missing header, and — with a warning, as it
 * means the server and this controller come from different versions of the
 * bundle — for one this controller cannot read.
 */
function parseAssetHeader(value) {
  if (!value) {
    return [];
  }

  let data;
  try {
    data = JSON.parse(value);
  } catch (error) {
    console.warn(`[ux-sdc] The ${ASSETS_HEADER} header is not valid JSON; no component asset is loaded.`, error);

    return [];
  }

  if (data?.v !== PROTOCOL_VERSION || !Array.isArray(data.assets)) {
    console.warn(`[ux-sdc] The ${ASSETS_HEADER} header is not of version ${PROTOCOL_VERSION}, the one this controller reads; no component asset is loaded. Are the bundle and its JavaScript of the same version?`);

    return [];
  }

  const assets = [];
  const urls = new Set();
  for (const entry of data.assets) {
    if (!ASSET_TYPES.includes(entry?.type) || typeof entry.href !== 'string') {
      continue;
    }

    const url = toAbsoluteUrl(entry.href);
    if (url && !urls.has(url)) {
      urls.add(url);
      const attributes = entry.attributes !== null && typeof entry.attributes === 'object' ? entry.attributes : {};
      assets.push({ type: entry.type, href: entry.href, url, attributes });
    }
  }

  return assets;
}

// `<link rel="stylesheet" href="…" nonce="…" …>`, the order of a full page.
function createStylesheet(asset, nonce) {
  const link = document.createElement('link');
  link.setAttribute('rel', 'stylesheet');
  link.setAttribute('href', asset.href);
  setNonce(link, asset.attributes, nonce);
  setAttributes(link, asset.attributes);

  return link;
}

// `<script src="…" type="…" nonce="…" …>`, the order of a full page.
function createScript(asset, nonce) {
  const script = document.createElement('script');
  script.setAttribute('src', asset.href);
  if (asset.type === 'module') {
    script.setAttribute('type', 'module');
  } else if (typeof asset.attributes.type === 'string') {
    script.setAttribute('type', asset.attributes.type);
  }
  setNonce(script, asset.attributes, nonce);
  setAttributes(script, asset.attributes);
  // Dynamically inserted scripts are async by default; keep them executing
  // in insertion order, which is the priority order the server sent. The
  // property, not the attribute: `async = false` adds none.
  if (!script.hasAttribute('async')) {
    script.async = false;
  }

  return script;
}

// The nonce as an attribute, like a server-rendered tag (the browser hides it
// once the element is inserted): the nonce of the page, unless the entry has
// its own, or `false` for none.
function setNonce(element, attributes, pageNonce) {
  const nonce = 'nonce' in attributes ? attributes.nonce : pageNonce;
  if (typeof nonce === 'string' && nonce !== '') {
    element.setAttribute('nonce', nonce);
  }
}

// Every attribute of the entry but those placed already, in its order: a string
// value as it is, `true` as a bare attribute.
function setAttributes(element, attributes) {
  for (const [name, value] of Object.entries(attributes)) {
    if (name === 'nonce' || (name === 'type' && element.localName === 'script')) {
      continue;
    }
    if (value === true) {
      element.setAttribute(name, '');
    } else if (typeof value === 'string') {
      element.setAttribute(name, value);
    }
  }
}

/**
 * The CSP nonce of the page, read from the first element matching the selector.
 * The `nonce` property, not the attribute: browsers blank the attribute once
 * the element is parsed, so that the nonce cannot be read by CSS selectors.
 * A nonce sent with the fragment would be of no use: the policy that applies
 * is the one the page was loaded with.
 */
function documentNonce(selector) {
  for (const element of document.querySelectorAll(selector)) {
    if (element.nonce) {
      return element.nonce;
    }
  }

  return '';
}

/**
 * The script nonce Turbo uses for the scripts it inserts itself, from
 * `<meta name="csp-nonce">`: for a page whose own scripts carry no nonce.
 */
function turboNonce() {
  const meta = document.querySelector('meta[name="csp-nonce"]');
  if (!meta) {
    return '';
  }

  return meta.nonce || meta.content || '';
}

/**
 * Resolves a path the same way the browser resolves `href`/`src`, so it can be
 * compared exactly with the `href`/`src` properties of existing elements.
 */
function toAbsoluteUrl(path) {
  if (!path) {
    return null;
  }

  try {
    return new URL(path, document.baseURI).href;
  } catch {
    return null;
  }
}

// The whole document is searched, not only <head>: render_component_assets()
// may be placed in <body>, and the page may already ship the asset itself.
function hasStylesheet(url) {
  return Array.from(document.querySelectorAll('link[rel~="stylesheet"][href]'))
    .some((link) => link.href === url);
}

function hasScript(url) {
  return Array.from(document.querySelectorAll('script[src]'))
    .some((script) => script.src === url);
}
