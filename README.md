# UX Twig Component Asset Bundle

![Packagist Version](https://img.shields.io/packagist/v/tito10047/ux-twig-component-asset)
![Packagist License](https://img.shields.io/packagist/l/tito10047/ux-twig-component-asset)
![Packagist Downloads](https://img.shields.io/packagist/dt/tito10047/ux-twig-component-asset)

✨ **Live demo is available here: [https://formalitka.mostka.sk/](https://formalitka.mostka.sk/)**
# UX Twig Component Asset Bundle

A Symfony bundle designed to bridge the gap between **AssetMapper** and **Twig Components**. It allows you to define component-specific CSS and JS directly in your PHP classes, ensuring assets are loaded **only when needed** and without "phantom" Stimulus controllers.

## Quick Example

The bundle is designed to work perfectly with a **Single Directory Component** architecture. Everything related to your component lives in one place.

```text
src/
└── Component/
    └── Alert/
        ├── Alert.php           # Logic with #[AsTwigComponent] and #[Asset]
        ├── Alert.html.twig     # Template
        ├── Alert.css           # Component styles (Auto-discovered!)
        └── alert_controller.js # Optional Stimulus controller

```

```php
namespace App\Component\Alert;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Tito10047\UxTwigComponentAsset\Attribute\Asset;

#[AsTwigComponent('Alert')]
#[Asset] // That's it! CSS/JS/Twig are now linked to this component.
class Alert
{
    public string $type = 'info';
}

```

> [!TIP]
> **Magic Automation:** The bundle automatically resolves the HTML template path and injects the required CSS and JS into your HTML header. No manual imports in `app.js` or dummy Stimulus controllers required.

---

## Why this bundle?

This project is a direct response to the architectural challenges discussed in **["A Better Architecture for Your Symfony UX Twig Components"](https://hugo.alliau.me/blog/posts/a-better-architecture-for-your-symfony-ux-twig-components)** by **Hugo Alliaume**.

It solves the "AssetMapper struggle": loading component-specific styles without forcing a Flash of Unstyled Content (FOUC) or creating unnecessary JavaScript overhead.

## Installation & Setup

1. **Install via Composer:**
```bash
composer require tito10047/ux-twig-component-asset

```


2. **Add the placeholder to your base template:**
   Place this in your `<head>` to tell the bundle where to inject the collected assets:
```twig
<head>
    {# ... #}
    {{ render_component_assets() }}
</head>

```



## Key Features

* **Auto-discovery:** If `Alert.php` has a sibling `Alert.css`, it's automatically included.
* **Performance:** Uses a **Compiler Pass** to map assets at build time, ensuring zero reflection overhead in production.
* **Smart Injection:** Assets are injected into the response only if the component was actually rendered on the page.
* **HTTP Preload:** Automatically adds `Link` headers for all collected assets to trigger early browser downloads.


