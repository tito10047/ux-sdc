# Development Guidelines for UX\TwigComponentSdc

This project is a Symfony bundle designed to manage CSS and JS assets for Twig components using a component-oriented approach.

## 1. Build & Configuration Instructions

### Prerequisites
- PHP 8.1 to 8.5
- Symfony 6.4 to 8.0
- Composer

### Installation
To set up the project for development:
```bash
composer install
```

### Bundle Registration
For use in a Symfony application, register the bundle in `config/bundles.php`:
```php
return [
    // ...
    Tito10047\UX\TwigComponentSdc\UX\TwigComponentSdc::class => ['all' => true],
];
```

## 2. Testing Information

### Configuring and Running Tests
We use PHPUnit for testing. The configuration is located in `phpunit.xml.dist`.
To run the tests, use:
```bash
./vendor/bin/phpunit
```

### Guidelines for Adding New Tests
- **Unit Tests**: Place in `tests/` and follow the `*Test.php` naming convention.
- **Integration Tests**: Test the bundle's integration with the Symfony container.
- **Mocking**: Use PHPUnit's built-in mocking capabilities or Symfony's `KernelTestCase` for integration tests.

### Example Test
A simple test to verify the bundle initialization:
```php
namespace Tito10047\UX\TwigComponentSdc\Tests;

use PHPUnit\Framework\TestCase;
use Tito10047\UX\TwigComponentSdc\UX\TwigComponentSdc;

class UX\TwigComponentSdcTest extends TestCase
{
    public function testBundleIsInstantiable(): void
    {
        $bundle = new UX\TwigComponentSdc();
        $this->assertInstanceOf(UX\TwigComponentSdc::class, $bundle);
    }
}
```

## 3. Additional Development Information

### Code Style
- Follow PSR-12 coding standards.
- Use strict typing where possible.
- Mirror existing patterns (e.g., directory structure in `src/` and `config/`).

### Project Architecture (from `zadanie.md`)
The bundle consists of several key components that need to be implemented:
- `Asset`: Attribute for marking component classes with their assets.
- `AssetRegistry`: Service to collect assets during a request.
- `AssetComponentCompilerPass`: Scans components at build time to prepare an asset map (supports auto-discovery).
- `ComponentRenderListener`: Listens to `PreCreateForRenderEvent` to trigger asset collection.
- `AssetResponseListener`: Injects collected assets into the HTML response and adds preload headers.
- `AssetExtension`: Twig extension for the `render_component_assets()` function.

### Key Logic
- Assets are collected only when a component is actually rendered.
- Placeholders in HTML are replaced in `KernelEvents::RESPONSE`.
- HTTP `Link` headers with `rel=preload` should be added for optimized loading.

## Benchmarks
./vendor/bin/phpbench run tests/Visual/ComponentBenchmark.php --bootstrap=tests/Visual/bootstrap.php --report=default