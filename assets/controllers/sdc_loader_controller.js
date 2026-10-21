// Loads the CSS/JS of SDC components that first appear on the page through a
// Live Component re-render.
//
// On a full page load the server injects component assets into the HTML. A
// Live Component AJAX re-render cannot do that, so the server lists the asset
// URLs in response headers (comma-separated, in priority order) and this
// controller adds the ones the document does not have yet:
//
//   X-SDC-Assets-CSS         stylesheets
//   X-SDC-Assets-JS          scripts loaded as ES modules (the default)
//   X-SDC-Assets-JS-Classic  scripts an asset opted out of modules for
//
// Under a Content Security Policy with nonces, the new elements get the nonce of
// the page they are added to, taken from an element that already carries one.
//
// The controller is registered automatically through the bundle's
// `assets/package.json`; attach it once per page, ideally on <body>:
//
//     <body {{ stimulus_controller(sdc_loader_controller) }}>
//
// It deliberately has no dependency on `@symfony/ux-live-component`: it is
// registered in every application, with or without LiveComponent. It only
// reacts to the `live:connect` / `live:disconnect` DOM events, which simply
// never fire when LiveComponent is not installed.

import { Controller } from '@hotwired/stimulus';

const CSS_HEADER = 'X-SDC-Assets-CSS';
const JS_HEADER = 'X-SDC-Assets-JS';
const CLASSIC_JS_HEADER = 'X-SDC-Assets-JS-Classic';

export default class extends Controller {
  initialize() {
    this.onLiveConnect = this.onLiveConnect.bind(this);
    this.onLiveDisconnect = this.onLiveDisconnect.bind(this);
    this.onRenderStarted = this.onRenderStarted.bind(this);
    // Live components we have hooked into. Kept so every hook can be removed
    // again, and so a component that reconnects is not hooked twice.
    this.components = new Set();
  }

  connect() {
    // Capture phase on the document: the events bubble from the component
    // element, so this works wherever on the page the controller is attached.
    document.addEventListener('live:connect', this.onLiveConnect, true);
    document.addEventListener('live:disconnect', this.onLiveDisconnect, true);
  }

  disconnect() {
    document.removeEventListener('live:connect', this.onLiveConnect, true);
    document.removeEventListener('live:disconnect', this.onLiveDisconnect, true);

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
    const headers = backendResponse?.response?.headers;
    if (!headers) {
      return;
    }

    const styleNonce = documentNonce('link[nonce], style[nonce]');
    for (const url of parseAssetHeader(headers.get(CSS_HEADER))) {
      if (!hasStylesheet(url)) {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = url;
        if (styleNonce) {
          link.nonce = styleNonce;
        }
        document.head.appendChild(link);
      }
    }

    const scriptNonce = documentNonce('script[nonce]');
    for (const url of parseAssetHeader(headers.get(JS_HEADER))) {
      addScript(url, true, scriptNonce);
    }
    for (const url of parseAssetHeader(headers.get(CLASSIC_JS_HEADER))) {
      addScript(url, false, scriptNonce);
    }
  }
}

function addScript(url, isModule, nonce) {
  if (hasScript(url)) {
    return;
  }

  const script = document.createElement('script');
  if (isModule) {
    script.type = 'module';
  }
  script.src = url;
  if (nonce) {
    script.nonce = nonce;
  }
  // Dynamically inserted scripts are async by default; keep them executing
  // in insertion order, which is the priority order the server sent.
  script.async = false;
  document.head.appendChild(script);
}

/**
 * The CSP nonce of the page, read from the first element matching the selector.
 * The `nonce` property, not the attribute: browsers blank the attribute once
 * the element is parsed, so that the nonce cannot be read by CSS selectors.
 * A nonce sent with the re-render response would be of no use: the policy that
 * applies is the one the page was loaded with.
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
 * Splits a comma-separated asset header into absolute URLs, dropping blanks
 * and duplicates. Returns an empty list for a missing or empty header.
 */
function parseAssetHeader(value) {
  if (!value) {
    return [];
  }

  const urls = [];
  for (const part of value.split(',')) {
    const url = toAbsoluteUrl(part.trim());
    if (url && !urls.includes(url)) {
      urls.push(url);
    }
  }

  return urls;
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
