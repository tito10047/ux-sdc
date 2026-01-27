# Development Guidelines for UX\Sdc

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
    Tito10047\UX\Sdc\UX\Sdc::class => ['all' => true],
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
namespace Tito10047\UX\Sdc\Tests;

use PHPUnit\Framework\TestCase;
use Tito10047\UX\Sdc\UX\Sdc;

class UX\SdcTest extends TestCase
{
    public function testBundleIsInstantiable(): void
    {
        $bundle = new UX\Sdc();
        $this->assertInstanceOf(UX\Sdc::class, $bundle);
    }
}
```

## 3. Additional Development Information

### Code Style
- Follow PSR-12 coding standards.
- Use strict typing where possible.
- Mirror existing patterns (e.g., directory structure in `src/` and `config/`).

