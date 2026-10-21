# UX SDC Bundle

[![Build Status](https://img.shields.io/github/actions/workflow/status/tito10047/ux-sdc/ci.yml?branch=main)](https://github.com/tito10047/ux-sdc/actions)
[![PHP-CS-Fixer](https://img.shields.io/github/actions/workflow/status/tito10047/ux-sdc/ci.yml?branch=main&label=code%20style)](https://github.com/tito10047/ux-sdc/actions/workflows/ci.yml)
[![PHPStan](https://img.shields.io/github/actions/workflow/status/tito10047/ux-sdc/ci.yml?branch=main&label=phpstan)](https://github.com/tito10047/ux-sdc/actions/workflows/ci.yml)
[![Latest Stable Version](https://img.shields.io/packagist/v/tito10047/ux-sdc.svg)](https://packagist.org/packages/tito10047/ux-sdc)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D%208.1-8892bf.svg)](https://php.net)
[![Symfony Version](https://img.shields.io/badge/Symfony-%3E%3D%206.4-black?logo=symfony)](https://symfony.com/)
[![Symfony Style](https://img.shields.io/badge/code%20style-symfony-black?logo=symfony)](https://symfony.com/)

A Symfony bundle that implements the **Single Directory Component (SDC)** methodology for Symfony UX. It bridges the gap between **AssetMapper** and **Twig Components** by providing a fully automated, convention-over-configuration workflow.

## Real-world Usage & Developer Experience

This bundle is actively used in production. Here are some real-world examples:
- [formalitka.mostka.sk](https://formalitka.mostka.sk/)
- [mostka.sk](https://mostka.sk/)
- [ycon.cc](https://ycon.cc)

### Developer Evaluation
Working with UX SDC provides an excellent developer experience. A recommended project structure organizes the code into distinct functional areas:
- **UI**: Generic interface elements (e.g., `Button`, `Spinner`, `Tabs`).
- **Layout**: Structural page elements (e.g., `TopBar`, `Footer`, `FlashMessage`).
- **Component**: Reusable feature blocks.
- **Page**: Complete page components (e.g., `Homepage`, `AboutUs`).

A significant advantage of this architecture is the ability to place Symfony controllers directly within the page-level SDC component directory (e.g., `HomepageAction.php`). The controller merely handles routing and renders the base layout, while all business and presentation logic remains encapsulated in isolated SDC components.

Because the code is highly granular and strictly structured, AI tools work exceptionally well within this architecture, easily generating robust and creative design implementations.

## AI Agent Skill

An agent skill for this bundle is available at **[tito10047/ux-sdc-skills](https://github.com/tito10047/ux-sdc-skills)**. It teaches coding agents how SDC components are discovered, how their assets reach the browser, and which traps the bundle sets — instead of letting them guess from the package name.

```
/plugin marketplace add tito10047/ux-sdc-skills
/plugin install ux-sdc-skills
```

For agents other than Claude Code, the skill is a plain directory of Markdown following the [agentskills.io](https://agentskills.io) layout; `AGENTS.md`, `CLAUDE.md` and `llms.txt` are provided as well.

It covers the recommended project structure and the `make:sdc-component --action` page workflow, asset discovery and injection, Stimulus controller naming, Live Component asset headers, and the build-time and runtime metadata pipelines (`metadata_cache`) — plus a set of failure modes with their root causes, including several that are not documented here:

- Routes on co-located `--action` controllers reach the router through autoconfiguration, which requires `controllers: { resource: routing.controllers }` in `config/routes.yaml`. That loader arrived in Symfony 7.4; on earlier versions add an explicit routing resource for the component directory, or every generated page returns 404.
- Use exactly one component attribute per class. `#[AsSdcComponent]` does not need `#[AsTwigComponent]`, and `#[AsLiveComponent]` does not need `#[AsSdcComponent]`; stacking two fails the container with *"another component already has this name"*.

## The Concept

This bundle is inspired by the architectural challenges discussed in **["A Better Architecture for Your Symfony UX Twig Components"](https://hugo.alliau.me/blog/posts/a-better-architecture-for-your-symfony-ux-twig-components)** by **Hugo Alliaume**.

Instead of scattering your component files across `src/`, `templates/`, and `assets/`, this bundle allows you to keep everything in one place.

## Quick Example

Just create a directory for your component. Everything else is handled automatically.
```yaml
ux_sdc:
    ux_components_dir: '%kernel.project_dir%/src_component/Component'
    component_namespace: 'App\Component'
```
```json
{
   "autoload": {
      "psr-4": {
         "App\\": "src/",
         "App\\Component\\": "src_component/"
      }
   }
}
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

use Tito10047\UX\Sdc\Attribute\AsSdcComponent;
use Tito10047\UX\Sdc\Twig\ComponentNamespaceInterface;
use Tito10047\UX\Sdc\Twig\Stimulus;

#[AsSdcComponent] // No need to define names, templates, or assets. It's all inferred!
class Alert
{
    use Stimulus;

    public string $type = 'info';
    public string $message;
}
```

In the `Alert.html.twig` template, you can then use the automatically generated stimulus controller name:
```twig
<div class="alert alert-{{ type }}" {{ stimulus_controller(controller) }}>
    {{ message }}
</div>
```

> [!TIP]
> **Zero Configuration Magic:** The bundle automatically registers the component, maps the template based on its location, and injects the required CSS/JS into your HTML header only when the component is rendered.

---

## Key Features

* **Automatic Registration:** Every class marked with `#[AsSdcComponent]` is automatically discovered and registered.
* **Smart Template Mapping:** Forget `template: 'components/Alert.html.twig'`. If the template is in the same folder as your class, it's found automatically.
* **Asset Orchestration:** CSS and JS files in your component folder are collected during rendering and injected into the `<head>`.
* **Automatic Stimulus Controllers:** By using the `Stimulus` trait and `ComponentNamespaceInterface`, your component automatically gets a `controller` variable representing its Stimulus controller name based on its namespace.
* **Support for Live Components:** Works seamlessly with `#[AsLiveComponent]` and `#[Asset]` attributes for modern, reactive interfaces.
* **No "Phantom" Controllers:** Load component-specific CSS via **AssetMapper** without the need for empty Stimulus controllers just for imports.
* **Performance First:** * **Compiler Pass:** All file discovery happens at build time. Zero reflection in production.
* **Response Post-processing:** Assets are injected at the end of the request.
* **HTTP Preload:** Automatic generation of `Link` headers to trigger early browser downloads.
* **Maker Command:** Quickly generate new SDC components with all necessary files using `php bin/console make:sdc-component`.



---

## Installation & Setup

1. **Install via Composer:**
```bash
composer require tito10047/ux-sdc
```

2. **Register the bundle** (if not done automatically by Symfony Flex):

```php
// config/bundles.php
return [
    // ...
    Tito10047\UX\Sdc\SdcBundle::class => ['all' => true],
];
```

3. **Configure the bundle:**
Create a configuration files:
```json
/*composer.json*/
{
    "autoload": {
        "psr-4": {
            "App\\": "src/",
            "App\\Component\\": "src_component/"
        }
    }
}
```
```yaml
#config/packages/ux_sdc.yaml
ux_sdc:
    ux_components_dir: '%kernel.project_dir%/src_component'
    component_namespace: 'App\Component'
    # ux_components_dir is also added to StimulusBundle's controller paths, next to
    # the application's own (assets/controllers by default); false opts out.
    stimulus:
        enabled: true
    # ux_components_dir is an AssetMapper path, so component CSS and JS can be served.
    # Its Twig templates are kept out of the asset map (and out of public/assets/);
    # set this to false only if you want them mapped.
    exclude_templates_from_asset_map: true
    # Where component metadata (CSS/JS paths and the template) comes from:
    #   true  - resolved once, when the container is built, and cached in
    #           %kernel.cache_dir%/sdc_metadata.php. No filesystem lookups on render.
    #   false - resolved on render from the files next to each component class, so
    #           a new or renamed CSS/JS/Twig file is picked up without cache:clear.
    #   auto  - false when kernel.debug is on, true otherwise.
    metadata_cache: auto
```

`metadata_cache` follows the debug flag, not the environment name: a `staging`
environment running with `APP_DEBUG=1` resolves metadata on render, and `prod` or
`test` without debug use the cache. The choice is made when the container is built,
so the option cannot come from an environment variable. With `true`, the cache is
rebuilt with the container: adding or removing an asset file next to a component
then needs a `cache:clear` (editing an existing file does not). With `false`, a
long-running worker (FrankenPHP, RoadRunner) remembers what it resolved for a
component until the worker restarts.

4. **Add the placeholder to your base template:**
   Place this in your `<head>` to define where the collected assets should be injected:
```twig
<head>
    {# ... #}
    {{ render_component_assets() }}
</head>
```

## Usage

### Live Components

The bundle also supports **Live Components**. It handles both initial rendering and AJAX updates automatically.

#### How it works with Live Components:
1. **Initial Render:** Assets are collected and injected into the `<head>` just like with regular Twig components.
2. **AJAX Updates:** When a Live Component is updated via AJAX, the bundle identifies the request and sends asset paths via HTTP headers (`X-SDC-Assets-CSS` and `X-SDC-Assets-JS`) to the browser.
3. **Dynamic Loading:** The bundle includes a Stimulus controller that listens for Live Component events and dynamically injects any missing assets into the `<head>` of the document.

#### Enabling Dynamic Asset Loading

To enable dynamic loading of assets during Live Component updates, you must add the bundle's Stimulus controller to your base template (ideally on the `<body>` element):

```twig
<body {{ stimulus_controller(sdc_loader_controller) }}>
    {# ... #}
</body>
```

#### Usage with Live Components:
To get full support (including the `controller` variable in Twig), your Live Component should implement 
`ComponentNamespaceInterface` and use the `Stimulus` trait:

```php
namespace App\Component\Search;

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Tito10047\UX\Sdc\Attribute\Asset;
use Tito10047\UX\Sdc\Twig\ComponentNamespaceInterface;
use Tito10047\UX\Sdc\Twig\Stimulus;

#[AsLiveComponent]
#[Asset] // Automatically discovers Search.css and Search_controller.js
class Search implements ComponentNamespaceInterface
{
    use DefaultActionTrait;
    use Stimulus;

    // ... logic
}
```

In your `Search.html.twig`:
```twig
<div
    {{ attributes.defaults({
        class: 'wizard [ l-container ]',
        'data-controller': controller,
        ('data-' ~ controller ~ '-sound-value'): asset('sound/stamp-102627.mp3'),
        ('data-' ~ controller ~ '-intensities-value'): intensityMap|json_encode
    }) }}
>
   <!-- ... -->
</div>
```

### Generating Components

You can use the built-in maker command to create a new component:

`php bin/console make:sdc-component UI:Alert`

This will create:
- `src/Component/UI/Alert/Alert.php` (PHP logic)
- `src/Component/UI/Alert/Alert.html.twig` (Twig template)
- `src/Component/UI/Alert/Alert.css` (CSS styles)
- (Optional) `src/Component/UI/Alert/Alert_controller.js` (Stimulus controller)

The maker supports options and interactive mode:
- `--stimulus` to force generating a Stimulus controller (non-interactive mode will not create it unless explicitly set)
- `--action` to generate a minimal controller action and a wrapper Twig template for the component

Example with an action:

```bash
php bin/console make:sdc-component Page:Homepage --action
```

This will additionally create:
- `src/Component/Page/Homepage/HomepageAction.php` (Symfony controller)
- `src/Component/Page/Homepage/HomepageAction.html.twig` (page template rendering the component)

Generated files contents:

```php
// src/Component/Page/Homepage/HomepageAction.php
namespace App\Component\Page\Homepage;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;

class HomepageAction extends AbstractController
{
    #[Route('/en', name: 'app.homepage')]
    public function index(): \Symfony\Component\HttpFoundation\Response
    {
        return $this->render('Page/Homepage/HomepageAction.html.twig');
    }
}
```

```twig
{# src/Component/Page/Homepage/HomepageAction.html.twig #}
{% extends 'layout.html.twig' %}

{% block content %}
    <twig:Page:Homepage:Homepage />
{% endblock %}
```

In interactive mode, you will be asked:
- for the component name (supports `:` or `/` separators, e.g. `UI:Alert` or `UI/Alert`)
- whether to generate a Stimulus controller
- whether to generate an Action class and template

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
| **Warmup (Dev/Debug)** | 809.8ms | 782.0ms | -27.8ms |
| **Warmup (Prod)** | 583.1ms | 586.2ms | +3.1ms |
| **Render (Prod Runtime)** | 26.5ms | 31.6ms | +5.1ms |
| **Render (Dev Runtime - 500 unique)** | 26.5ms | 88.4ms | +61.9ms |
| **Render (Dev Runtime - 10 unique repeated)** | 26.5ms | 58.0ms | +31.5ms |

### Key Findings
- **Developer Experience (Dev Runtime):** In `dev` mode, there is a measurable overhead for **unique** components (~84µs per component) due to runtime autodiscovery. This allows developers to add CSS/JS/Twig files and see changes instantly without clearing the cache.
- **Caching:** Thanks to internal metadata caching, repeated rendering of the same component in `dev` is significantly faster as the file system is only scanned once per unique component class per request.
- **Production Performance:** In `prod` mode, the overhead for rendering 500 unique components is practically zero, as all metadata is pre-generated during container compilation.
- **Warmup:** The SDC approach slightly increases container compilation time in `prod` (~15ms for 500 unique components) but remains very efficient.
- **Memory Usage:** The SDC approach requires approximately **8MB** more memory during container compilation for 500 components, which is well within acceptable limits for modern applications.

For detailed results and methodology, see the [Full Benchmark Report](benchmark.md).

## License

MIT