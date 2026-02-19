// Stimulus controller for dynamically loading SDC assets (CSS/JS)
// Listens to Symfony UX LiveComponent "live:render:finished" events and
// ensures assets declared via data-sdc-css / data-sdc-js are present once in <head>.
//
// Usage in app (example):
// import SdcLoaderController from '@tito10047/ux-sdc/assets/controllers/sdc_loader_controller.js';
// application.register('sdc-loader', SdcLoaderController);

import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';


export default class extends Controller {
  connect() {
    this._onLiveConnect = this._onLiveConnect.bind(this);
    document.addEventListener('live:connect', this._onLiveConnect, true);
  }

  disconnect() {
    document.removeEventListener('live:connect', this._onLiveConnect, true);
  }

  _onLiveConnect(event) {
    const component = event.detail.component;

    // Zavesíme sa na interný hook 'render:started'
    // Tento hook má prístup k response skôr, ako sa prepíše DOM
    component.on('render:started', (html, { response }) => {
      if (response) {
        const css = response.headers.get('X-SDC-Assets-CSS');
        const js = response.headers.get('X-SDC-Assets-JS');

        if (css) {
          this._ensureAssets(css.split(',').map(s => s.trim()).filter(Boolean), 'css');
        }
        if (js) {
          this._ensureAssets(js.split(',').map(s => s.trim()).filter(Boolean), 'js');
        }
      }
    });
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
