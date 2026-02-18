// Stimulus controller for dynamically loading SDC assets (CSS/JS)
// Listens to Symfony UX LiveComponent "live:render:finished" events and
// ensures assets declared via data-sdc-css / data-sdc-js are present once in <head>.
//
// Usage in app (example):
// import SdcLoaderController from '@tito10047/ux-sdc/assets/controllers/sdc_loader_controller.js';
// application.register('sdc-loader', SdcLoaderController);

import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  connect() {
    this._onLiveRenderFinished = this._onLiveRenderFinished.bind(this);
    document.addEventListener('live:render:finished', this._onLiveRenderFinished);

    // Handle initial page load (e.g. Turbo or plain)
    this._processElement(document.documentElement);
  }

  disconnect() {
    document.removeEventListener('live:render:finished', this._onLiveRenderFinished);
  }

  _onLiveRenderFinished(event) {
    const el = event?.detail?.component?.element || event.target || null;
    if (!el) return;
    this._processElement(el);
  }

  _processElement(root) {
    const elements = [];
    if (root instanceof Element) {
      elements.push(root);
      elements.push(...root.querySelectorAll('[data-sdc-css], [data-sdc-js]'));
    }

    for (const el of elements) {
      const css = el.getAttribute('data-sdc-css');
      const js = el.getAttribute('data-sdc-js');

      if (css) {
        const list = css.split(',').map(s => s.trim()).filter(Boolean);
        this._ensureAssets(list, 'css');
      }
      if (js) {
        const list = js.split(',').map(s => s.trim()).filter(Boolean);
        this._ensureAssets(list, 'js');
      }
    }
  }

  _ensureAssets(list, type) {
    for (const path of list) {
      if (!path) continue;
      if (type === 'css') {
        const exists = Array.from(document.head.querySelectorAll('link[rel="stylesheet"]'))
          .some(link => link.href && (link.href === path || link.href.endsWith(path)));
        if (!exists) {
          const link = document.createElement('link');
          link.rel = 'stylesheet';
          link.href = path;
          document.head.appendChild(link);
        }
      } else if (type === 'js') {
        const exists = Array.from(document.head.querySelectorAll('script[src]'))
          .some(script => script.src && (script.src === path || script.src.endsWith(path)));
        if (!exists) {
          const script = document.createElement('script');
          script.src = path;
          script.defer = true;
          document.head.appendChild(script);
        }
      }
    }
  }
}
