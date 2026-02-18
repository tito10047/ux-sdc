# UX\Sdc

Tento bundle implementuje metodológiu **Single Directory Component (SDC)** pre Symfony UX.

## Podpora Live Components

Bundle plne podporuje **Live Components**, vrátane AJAXových aktualizácií.

### Kľúčové vlastnosti pre Live Components:
- **Automatické nastavenie namespace:** Pri AJAX requestoch na Live komponenty sa automaticky nastavuje namespace, ak komponent implementuje `ComponentNamespaceInterface`.
- **Dynamické načítavanie assetov:** Bundle vkladá atribúty `data-sdc-css` a `data-sdc-js`, ktoré náš Stimulus controller používa na dynamické načítanie chýbajúcich CSS a JS súborov pri aktualizácii komponentu.

### Inštalácia do base šablóny

Pre správne fungovanie (hlavne dynamické načítavanie assetov pri Live komponentoch) je potrebné pridať náš controller do `<body>` a placeholder do `<head>`:

```twig
<head>
    {# ... #}
    {{ render_component_assets() }}
</head>
<body {{ stimulus_controller(sdc_loader_controller) }}>
    {# ... #}
</body>
```

## Použitie s Live Component

```php
namespace App\Component;

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Tito10047\UX\Sdc\Attribute\Asset;
use Tito10047\UX\Sdc\Twig\ComponentNamespaceInterface;
use Tito10047\UX\Sdc\Twig\Stimulus;

#[AsLiveComponent]
#[Asset]
class MyLiveComponent implements ComponentNamespaceInterface
{
    use DefaultActionTrait;
    use Stimulus;
}
```

V šablóne môžete pristupovať k premennej `controller`:

```twig
<div {{ attributes }} {{ stimulus_controller(controller) }}>
    ...
</div>
```
