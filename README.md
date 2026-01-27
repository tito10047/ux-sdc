# UX Twig Component SDC Bundle

[![Build Status](https://img.shields.io/github/actions/workflow/status/tito10047/ux-twig-component-sdc/ci.yml?branch=main)](https://github.com/tito10047/ux-twig-component-sdc/actions)
[![PHP-CS-Fixer](https://img.shields.io/github/actions/workflow/status/tito10047/ux-twig-component-sdc/ci.yml?branch=main&label=code%20style)](https://github.com/tito10047/ux-twig-component-sdc/actions/workflows/ci.yml)
[![PHPStan](https://img.shields.io/github/actions/workflow/status/tito10047/ux-twig-component-sdc/ci.yml?branch=main&label=phpstan)](https://github.com/tito10047/ux-twig-component-sdc/actions/workflows/ci.yml)
[![Latest Stable Version](https://img.shields.io/packagist/v/tito10047/ux-twig-component-sdc.svg)](https://packagist.org/packages/tito10047/ux-twig-component-sdc)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D%208.1-8892bf.svg)](https://php.net)
[![Symfony Version](https://img.shields.io/badge/Symfony-%3E%3D%206.4-black?logo=symfony)](https://symfony.com/)
[![Symfony Style](https://img.shields.io/badge/code%20style-symfony-black?logo=symfony)](https://symfony.com/)

A Symfony bundle that implements the **Single Directory Component (SDC)** methodology for Symfony UX. It bridges the gap between **AssetMapper** and **Twig Components** by providing a fully automated, convention-over-configuration workflow.

## The Concept

This bundle is inspired by the architectural challenges discussed in **["A Better Architecture for Your Symfony UX Twig Components"](https://hugo.alliau.me/blog/posts/a-better-architecture-for-your-symfony-ux-twig-components)** by **Hugo Alliaume**.

Instead of scattering your component files across `src/`, `templates/`, and `assets/`, this bundle allows you to keep everything in one place.

## Quick Example

Just create a directory for your component. Everything else is handled automatically.
```yaml
twig_component_sdc:
    ux_components_dir: '%kernel.project_dir%/src/Component'
    component_namespace: 'App\Component'
```
```text
src_component/
└── Component/
    └── Alert/
        ├── Alert.php           # Auto-registered logic
        ├── Alert.html.twig     # Auto-mapped template
        ├── Alert.css           # Auto-injected styles
        └── alert_controller.js # Auto-mapped Stimulus controller

```

```php
namespace App\Component\Alert;

use Tito10047\UX\TwigComponentSdc\Attribute\AsSdcComponent;

#[AsSdcComponent] // No need to define names, templates, or assets. It's all inferred!
class Alert
{
    public string $type = 'info';
    public string $message;
}

```

> [!TIP]
> **Zero Configuration Magic:** The bundle automatically registers the component, maps the template based on its location, and injects the required CSS/JS into your HTML header only when the component is rendered.

---

## Key Features

* **Automatic Registration:** Every class marked with `#[AsSdcComponent]` is automatically discovered and registered.
* **Smart Template Mapping:** Forget `template: 'components/Alert.html.twig'`. If the template is in the same folder as your class, it's found automatically.
* **Asset Orchestration:** CSS and JS files in your component folder are collected during rendering and injected into the `<head>`.
* **No "Phantom" Controllers:** Load component-specific CSS via **AssetMapper** without the need for empty Stimulus controllers just for imports.
* **Performance First:** * **Compiler Pass:** All file discovery happens at build time. Zero reflection in production.
* **Response Post-processing:** Assets are injected at the end of the request.
* **HTTP Preload:** Automatic generation of `Link` headers to trigger early browser downloads.



---

## Installation & Setup

1. **Install via Composer:**
```bash
composer require tito10047/ux-twig-component-sdc
```

2. **Register the bundle** (if not done automatically by Symfony Flex):
```php
// config/bundles.php
return [
    // ...
    Tito10047\UX\TwigComponentSdc\TwigComponentSdcBundle::class => ['all' => true],
];
```

3. **Configure the bundle:**
Create a configuration file (e.g., `config/packages/twig_component_sdc.yaml`):

```yaml
twig_component_sdc:
    # Directory where your SDC components are located (default: %kernel.project_dir%/src_component)
    ux_components_dir: '%kernel.project_dir%/src/Component'
    
    # PHP Namespace for your components (required for auto-registration)
    component_namespace: 'App\Component'
    
    # Enable/disable auto-discovery of .twig, .css, and .js files (default: true)
    auto_discovery: true
    
    # Custom placeholder for asset injection (optional)
    # placeholder: '<!-- __UX_TWIG_COMPONENT_ASSETS__ -->'
    
    # Stimulus integration (auto-registers component directory in Stimulus)
    stimulus:
        enabled: true
```

4. **Add the placeholder to your base template:**
   Place this in your `<head>` to define where the collected assets should be injected:
```twig
<head>
    {# ... #}
    {{ render_component_assets() }}
</head>
```

---

## How It Works

1. **Discovery:** During container compilation, the bundle scans your component directory. It maps PHP classes to their neighboring `.twig`, `.css`, and `.js` files.
2. **Rendering:** When a component is used on a page, the bundle's listener intercepts the `PreCreateForRenderEvent` and logs its required assets.
3. **Injection:** The `AssetResponseListener` replaces your Twig placeholder with the actual `<link>` and `<script>` tags and adds HTTP preload headers to the response.

## Why SDC?

1. **Maintainability:** Everything related to a UI element is in one folder.
2. **Developer Experience:** No more jumping between four different directories to change one button's color.
3. **Efficiency:** Only the CSS/JS needed for the current page is sent to the user.

## Benchmarks

This bundle is designed for high performance with minimal overhead. We've conducted extensive benchmarks comparing the SDC approach with the classic Twig component approach.

### Performance Summary (500 Components)

| Scenario | Classic Approach | SDC Approach | Difference |
|----------|------------------|--------------|------------|
| **Warmup (Dev/Debug)** | 759.2ms | 745.4ms | -13.8ms |
| **Warmup (Prod)** | 578.8ms | 593.8ms | +15.0ms |
| **Render (Prod Runtime)** | 26.5ms | 27.2ms | +0.7ms |
| **Render (Dev Runtime)** | 26.5ms | 68.8ms | +42.3ms |

### Key Findings
- **Developer Experience (Dev Runtime):** In `dev` mode, there is a measurable overhead (~84µs per component) due to runtime autodiscovery. This allows developers to add CSS/JS/Twig files and see changes instantly without clearing the cache.
- **Production Performance:** In `prod` mode, the overhead for rendering 500 components is only about **0.7ms** (~1.4µs per component), which is practically negligible.
- **Warmup:** The SDC approach slightly increases container compilation time in `prod` (~15ms for 500 components) but remains very efficient.
- **Memory Usage:** The SDC approach requires approximately **8MB** more memory during container compilation for 500 components, which is well within acceptable limits for modern applications.

For detailed results and methodology, see the [Full Benchmark Report](benchmark.md).

## License

MIT