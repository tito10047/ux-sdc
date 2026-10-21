# Components

A component is a class in `ux_components_dir`, carrying `#[AsSdcComponent]`,
`#[AsTwigComponent]` or `#[AsLiveComponent]`, with its template, stylesheet,
script and Stimulus controller next to it. A template without a class is an
[anonymous component](#anonymous-components).

## Component names

The component name is the class name relative to `component_namespace`, with
`\` as `:` — `App\Component\Alert\Alert` is `Alert:Alert`, and
`App\Component\UI\Button\Button` is `UI:Button:Button`:

```twig
<twig:Alert:Alert type="success" message="Saved." />
```

Pass a name to the attribute to choose another one: `#[AsSdcComponent('Alert')]`.

## Co-located files

For a class `Alert`, the bundle looks next to `Alert.php` for:

| File | Used as |
|------|---------|
| `Alert.html.twig` | the component template |
| `Alert.css` | a stylesheet, added to `<head>` when the component renders |
| `Alert.js` | a [module script](assets.md#scripts-are-es-modules), added to `<head>` when the component renders |
| `Alert_controller.js` | a Stimulus controller, loaded by StimulusBundle (see [Stimulus controllers](#stimulus-controllers)) |

The file names follow the class name exactly, case included. Rename the class,
rename the files. Discovery can be turned off with `auto_discovery: false`; then
templates and assets have to be declared explicitly.

Only these names are discovered: one stylesheet and one script per component.
Declare any further file next to the class with [`#[Asset]`](assets.md#asset), by
its logical path — which also says how it loads, something a file name could only
hint at:

```php
#[AsSdcComponent]
#[Asset('Alert/Alert.print.css', attributes: ['media' => 'print'])]
#[Asset('Alert/Inter.woff2')] // preloaded, see Fonts and images
final class Alert {}
```

## Templates

A template named explicitly always wins over the one next to the class:

```php
#[AsSdcComponent(template: 'components/alert.html.twig')] // Alert.html.twig next to the class is ignored
final class Alert {}
```

The same goes for `#[AsTwigComponent]`, `#[AsLiveComponent]` and a `twig.component`
service tag with a `template`. The component's CSS and JS are still discovered.

## Component attributes

Discovery works for `#[AsTwigComponent]` and `#[AsLiveComponent]` classes too.
`#[AsSdcComponent]` is `#[AsTwigComponent]` with two extra arguments, `css` and
`js`, and is only needed for them (see [Declaring assets](assets.md#declaring-assets)).

Use one component attribute per class: `#[AsLiveComponent]` combined with
`#[AsSdcComponent]` fails the container build with *"another component already has
this name"*.

## Stimulus controllers

Every component in `component_namespace` gets a `controller` template variable:
the name StimulusBundle registers for the `_controller.js` file next to its
class. `Alert_controller.js`:

```js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    close() {
        this.element.remove();
    }
}
```

`Alert.html.twig`:

```twig
<div {{ attributes.defaults({class: 'alert alert-' ~ type}).defaults(stimulus_controller(controller)) }}>
    {{ message }}
    <button type="button" {{ stimulus_action(controller, 'close') }}>&times;</button>
</div>
```

The name is the class path relative to `component_namespace`, with `\` as `--`
and `_` as `-`, case kept: `Alert/Alert.php` → `Alert--Alert`,
`UI/Date_Picker/Date_Picker.php` → `UI--Date-Picker--Date-Picker`.

- No trait or interface is needed. A `controller` the component exposes itself
  (a public property, `#[ExposeInTemplate]`) is left alone.
- Without `component_namespace`, or for a class outside it, the variable is not set.
- The `Tito10047\UX\Sdc\Twig\Stimulus` trait, together with
  `Tito10047\UX\Sdc\Twig\ComponentNamespaceInterface`, is only needed for the
  name in PHP (`$this->getController()`).
- `Alert.js` and `Alert_controller.js` are different things: the first is a
  `<script>` tag added with the component, the second a controller loaded by
  StimulusBundle. Do not write both for the same behaviour.

`stimulus.enabled: false` stops the bundle from adding `ux_components_dir` to the
Stimulus controller paths.

### Capital letters in controller names

StimulusBundle keeps the case of the file path, so a component controller is
registered as `UI--Calendar--Month`, while Stimulus expects lowercase
identifiers: the browser lowercases attribute names, and Stimulus compares some
of them with names it builds from the identifier as it is. With Stimulus 3.2:

- Targets, actions, action parameters, classes, reading and writing values
  (`this.countValue`) and outlets work.
- **`…ValueChanged()` callbacks are not called** when the value is in the
  markup — neither when the controller connects nor when the attribute changes
  later (`this.countValue = 2`, a Live Component re-render). Without the
  attribute, the callback runs once, for the default value. Read the value where
  you need it, and call the reaction yourself after changing it.
- **Changing an outlet's selector attribute** after the host connected is not
  noticed. Outlets present in the markup connect normally.
- **Outlet properties need brackets.** Stimulus turns `--` into `-` and only
  capitalizes a lowercase letter after a dash, so the properties of the outlet
  `UI--Calendar--Month` are `UI-Calendar-MonthOutlet`, `…Outlets`,
  `…OutletElement`, `…OutletElements` and `hasUI-Calendar-MonthOutlet`:

```twig
<div {{ stimulus_controller(controller, {}, {}, {'UI--Calendar--Month': '.calendar-month'}) }}>
```

```js
export default class extends Controller {
    static outlets = ['UI--Calendar--Month'];

    next() {
        if (this['hasUI-Calendar-MonthOutlet']) {
            this['UI-Calendar-MonthOutlet'].refresh();
        }
    }

    ['UI-Calendar-MonthOutletConnected'](outlet, element) {
        // also a computed name, like …OutletDisconnected
    }
}
```

A controller that needs `…ValueChanged()` or outlets that change selector needs a
lowercase name, which StimulusBundle only gives to a lowercase path: put it in
`assets/controllers/` (`assets/controllers/ui/calendar/month_controller.js` is
registered as `ui--calendar--month`, its outlet properties are
`uiCalendarMonthOutlet` and `hasUiCalendarMonthOutlet`) and name it in the
template, `stimulus_controller('ui/calendar/month')`, instead of using
`{{ controller }}`. The bundle has no option to lowercase `{{ controller }}`:
the name StimulusBundle registers is derived from the file path, and changing
only one side would name a controller that does not exist.

## Where components can live

Co-located files are loaded by the name they have where they are served from:

* the template by its **Twig name**, relative to the Twig path it sits in;
* the stylesheet and script by their **AssetMapper logical path**, relative to the
  AssetMapper path they sit in.

`ux_components_dir` is set up for this out of the box: the bundle registers it both
as a Twig path and as an AssetMapper path, so `src_component/Alert/Alert.css` is
`Alert/Alert.css` and `src_component/Alert/Alert.html.twig` is
`Alert/Alert.html.twig`. This is where single-directory components belong.

Every Twig component is processed, wherever its class lives, but a co-located file
without such a name is **not loaded**. A class in `src/Twig/Components/` with
`Alert.css` next to it therefore gets no stylesheet. Either move the component into
`ux_components_dir`, or reference the file explicitly by its AssetMapper logical
path:

```php
#[AsTwigComponent]
#[Asset('styles/alert.css')] // a file in assets/styles/, or any other AssetMapper path
final class Alert {}
```

A directory of your own works too, as long as it is both kinds of path. The names
follow each path's namespace, and the two namespaces are independent:

```yaml
# config/packages/twig.yaml
twig:
    paths:
        '%kernel.project_dir%/lib/ui': ui          # lib/ui/Badge/Badge.html.twig → @ui/Badge/Badge.html.twig

# config/packages/asset_mapper.yaml
framework:
    asset_mapper:
        paths:
            lib/ui: ui                              # lib/ui/Badge/Badge.css → ui/Badge/Badge.css
        # Keep the templates out of public/assets/; the bundle does this for ux_components_dir only.
        excluded_patterns:
            - '%kernel.project_dir%/lib/ui/*.twig'
```

(The classes in it have to be registered as services, like any other code outside
`ux_components_dir`.) A directory that is only a Twig path — `templates/`, for
example — gets its templates found, but its stylesheets and scripts cannot be
served and are left out.

## Anonymous components

A component without a class — only a template — gets the stylesheet and script
named after its template, next to it:

```
src_component/
└── Notice/
    ├── Notice.html.twig
    ├── Notice.css
    └── Notice.js
```

```twig
<twig:Notice:Notice message="Saved" />
```

The bundle lets Twig Components find anonymous components in `ux_components_dir`,
named after their path the way a class there is: `Notice/Notice.html.twig` is
`Notice:Notice`, and `Notice/index.html.twig` is `Notice`. Twig Components' own
`anonymous_template_directory` (`templates/components/` in a Flex application) is
looked up first, and a component class of the same name wins over both. An
anonymous component found there gets its assets by the same rules as any other
file (see [Where components can live](#where-components-can-live)):
`templates/components/Button.css` is not served by AssetMapper, so it is left out
and reported.

A template next to a PHP file of the same name is not an anonymous component: it
belongs to that class, as `Notice.html.twig` would to a `Notice.php`. That keeps
the page of a controller — `Page/Homepage/HomepageAction.html.twig` next to
`HomepageAction.php`, as [`--action`](maker.md#page-components-with---action)
generates it — out of the components, of `debug:sdc` and of the metadata cache.

An anonymous component gets no `controller` variable: its template name does not
tell where the file is without looking it up on every render. Name its Stimulus
controller instead — `{{ stimulus_controller('Notice--Notice') }}` for a
`Notice_controller.js` next to the template.

`auto_discovery: false` leaves anonymous components without assets: there is
nothing to declare them with.

## Organizing components

One layout that works well, and keeps a page's controller, template and styles
in one place:

```text
src_component/
├── UI/          # generic elements: Button, Spinner, Tabs
├── Layout/      # page frame: TopBar, Footer, FlashMessage
├── Component/   # reusable feature blocks
└── Page/        # one directory per page: Homepage/Homepage.php + HomepageAction.php
```

[`make:sdc-component Page:Homepage --action`](maker.md#page-components-with---action)
generates such a page.
