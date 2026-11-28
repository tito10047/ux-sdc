# Generating components

With `symfony/maker-bundle` installed:

```bash
php bin/console make:sdc-component UI:Alert
```

creates, in `ux_components_dir`:

```text
src_component/UI/Alert/
├── Alert.php            # #[AsSdcComponent] class App\Component\UI\Alert\Alert
├── Alert.html.twig
├── Alert.css
└── Alert_controller.js  # only with --stimulus, or when confirmed interactively
```

For `UI:DatePicker` with `--stimulus`:

```php
// DatePicker.php
namespace App\Component\UI\DatePicker;

use Tito10047\UX\Sdc\Attribute\AsSdcComponent;

#[AsSdcComponent]
final class DatePicker
{
}
```

```twig
{# DatePicker.html.twig #}
<div {{ attributes.defaults({class: 'date-picker'}).defaults(stimulus_controller(controller)) }}>
    {# The markup of DatePicker #}
</div>
```

```css
/* DatePicker.css */
.date-picker {
    /* Loaded only on the pages that render DatePicker. */
}
```

`DatePicker_controller.js` is a Stimulus controller with an empty `connect()`.
Its name, `UI--DatePicker--DatePicker`, keeps capitals, so Stimulus does not call
its `…ValueChanged()` callbacks; see
[Capital letters in controller names](components.md#capital-letters-in-controller-names).

The stylesheet is not in a cascade layer. Rules in an `@layer` lose to every
unlayered rule, whatever their specificity: against the unlayered `app.css` of a
Flex application, a layered component rule would never apply. Unlayered, and
loaded after the application's styles (see step 4 of
[Without the recipe](installation.md#without-the-recipe)), it wins wherever
specificity is equal. An application that orders its styles with layers wraps the
rules itself (`@layer components { … }`).

| Option | Effect |
|--------|--------|
| `name` | Component name; `:` or `/` separate directories (`UI:Alert`, `UI/Alert`). Asked for when omitted. |
| `--stimulus` | Also generate `<Name>_controller.js`. Without the option, asked interactively (default yes); never generated with `--no-interaction`. Needs `symfony/stimulus-bundle` (the template calls its `stimulus_controller()`): without it the option fails and the question is not asked. |
| `--action` | Also generate a page controller and its template (see below). Without the option, asked interactively (default no). |
| `--route-path=PATH` | Path of the page's route, instead of the one derived from the name. Implies `--action`. |
| `--layout=TEMPLATE` | Template the page extends, instead of the one detected. Implies `--action`. |

The maker needs `component_namespace`, and writes the PHP class where Composer's
PSR-4 mapping puts it and the other files into `ux_components_dir`. When the two
do not point to the same directory — or `component_namespace` is not set, or
Composer does not autoload it — it stops before writing anything and says which
setting to fix, rather than leave the class apart from its template and assets.

## Page components with `--action`

```bash
php bin/console make:sdc-component Page:Homepage --action
```

additionally creates a controller and a page template next to the component:

```php
// src_component/Page/Homepage/HomepageAction.php
namespace App\Component\Page\Homepage;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomepageAction extends AbstractController
{
    #[Route('/homepage', name: 'app_homepage')]
    public function __invoke(): Response
    {
        return $this->render('Page/Homepage/HomepageAction.html.twig');
    }
}
```

```twig
{# src_component/Page/Homepage/HomepageAction.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <twig:Page:Homepage:Homepage />
{% endblock %}
```

- **Route.** The path is the component name, each part kebab-cased, without a
  leading `Page` — the namespace of page components, see
  [Organizing components](components.md#organizing-components): `Page:Homepage` →
  `/homepage`, `Page:Blog:BlogPost` → `/blog/blog-post`, `Admin:Dashboard` →
  `/admin/dashboard`. The route name is the one `make:controller` would give the
  same name: `app_homepage`, `app_blog_blog_post`, `app_admin_dashboard`. Choose
  another path with `--route-path`; the name is yours to edit.
- **Layout.** The page extends `layout.html.twig` if Twig finds one, else
  `base.html.twig`, and fills the `content` block if that template has one —
  defined in it or in a template it extends — else `body`. On a Flex application
  that is `base.html.twig` and `body`. Choose another template with `--layout`.
  When there is no template to extend, or it has neither block, the maker stops
  and says so.
- **Route loading.** The route is loaded by the bundle's route import,
  `config/routes/ux_sdc.yaml` (created by the recipe, or step 5 of
  [Without the recipe](installation.md#without-the-recipe)), or on
  Symfony 7.4+ by `resource: routing.controllers` in `config/routes.yaml`. The
  maker reminds you of the import, or says it is there when
  `config/routes/ux_sdc.yaml` exists. `ux_sdc.routing.enabled: false` turns off both
  (see [Configuration](configuration.md)); the directory can then still be
  imported like any other, with `resource: {path: ../src_component/,
  namespace: App\Component}` and `type: attribute`. Check with
  `php bin/console debug:router app_homepage`.

The page template is not an anonymous component, although it sits in
`ux_components_dir`: a template next to a PHP file of the same name belongs to
that class (see [Anonymous components](components.md#anonymous-components)).
