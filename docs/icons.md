# Component icons

> **Experimental.** Requires [`symfony/ux-icons`](https://symfony.com/bundles/ux-icons/current/index.html) 2.36.1+ or 3.x with its bundle enabled; without it, the feature is off. Not covered by the [backward compatibility promise](../README.md#backward-compatibility) yet: the `icons` options, the `sdc:` names and `ux:sdc:icons:import` may change in a minor release.

An icon used by a single component can live in that component's folder, next to its template, CSS and JS, instead of in the global `assets/icons/`:

```
src_component/Alert/
  Alert.php
  Alert.html.twig
  Alert.css
  arrow.svg          ← used by Alert only
```

In the component's template, write the bare icon name; it is looked up in the component's folder first:

```twig
{# src_component/Alert/Alert.html.twig #}
<twig:ux:icon name="arrow" class="w-4" />   {# src_component/Alert/arrow.svg #}
{{ ux_icon('arrow') }}                      {# the same #}
{{ ux_icon('star') }}                       {# Alert has no star.svg: assets/icons/star.svg #}
<twig:ux:icon name="mdi:home" />            {# prefixed: the global icon, as before #}
```

What a name in a component's template resolves to:

| Written as | Resolves to |
|---|---|
| `arrow` (bare, constant) | `<component folder>/arrow.svg`, otherwise the global `arrow` |
| `mdi:home` (prefixed) | the global icon, untouched |
| an alias from `ux_icons.aliases` | the alias, untouched |
| `ux_icon(iconName)` (a variable) | the global icon, untouched: the name is not known at compile time |
| `sdc:alert:arrow` (full name) | Alert's `arrow.svg`, from any template (see below) |

Templates outside `ux_components_dir` are never affected. The lookup follows the file the icon is *written* in: an icon in `Alert.html.twig` belongs to Alert, even inside the content block it passes to another component.

## Using a component's icon from elsewhere

Every component icon also has a full name, `sdc:<component folder>:<icon>`, which works in any template: another component's or a regular one. The folder path relative to `ux_components_dir` is kebab-cased and its parts are joined with `:`:

| Icon file | Full name |
|---|---|
| `src_component/Alert/arrow.svg` | `sdc:alert:arrow` |
| `src_component/Form/TextInput/eye.svg` | `sdc:form:text-input:eye` |
| `src_component/UI/HTMLEditor/bold.svg` | `sdc:ui:html-editor:bold` |
| `src_component/logo.svg` (top level) | `sdc:logo` |

```twig
{# src_component/Toast/Toast.html.twig: borrows Alert's icon #}
<twig:ux:icon name="sdc:alert:arrow" />

{# templates/home.html.twig #}
{{ ux_icon('sdc:form:text-input:eye') }}
```

A full name is prefixed, so it is used as written, without the rewrite. `ux:sdc:icons:import` prints it after importing. Keep in mind:

- The name follows the folder: renaming or moving `Alert/` changes it, and templates that borrow its icons must change with it. An icon several components share is usually better in the global `assets/icons/`.
- With `fallback_to_global: true`, a full name whose file is missing (`sdc:alert:arrow` when Alert has no `arrow.svg`) renders the global `arrow`, the same as the bare name inside Alert would.

## Importing icons into a component

`ux:icons:import` always writes to the global icon directory, so the bundle has its own command, which uses the same Iconify service:

```bash
# saved as src_component/Alert/home.svg and close.svg
bin/console ux:sdc:icons:import Alert mdi:home mdi:close

# saved as src_component/Alert/arrow.svg
bin/console ux:sdc:icons:import Alert tabler:arrow-right --as=arrow

# by its folder, relative to ux_components_dir
bin/console ux:sdc:icons:import Form/TextInput mdi:eye
```

An icon the component already has is kept unless you pass `--force`. Icons are saved under their bare name, which is how the template asks for them.

## Configuration

```yaml
# config/packages/ux_sdc.yaml
ux_sdc:
    icons:
        enabled: true             # false turns the feature off
        subdir: ''                # 'icons' → src_component/Alert/icons/arrow.svg
        fallback_to_global: true  # false: a bare name the component lacks is an error

# config/packages/ux_icons.yaml
ux_icons:
    icon_sets:
        sdc:                      # attributes applied to every component icon
            icon_attributes:
                class: 'icon'
```

After enabling the feature (or upgrading to it), run `bin/console cache:clear` once so the existing templates are compiled again.

## How it works

1. While Twig compiles a template from `ux_components_dir`, a node visitor rewrites each constant bare icon name to one that names the component: `'arrow'` in `Form/TextInput/TextInput.html.twig` becomes `'sdc:form:text-input:arrow'`. It happens once, at compile time; the compiled template holds the final name and rendering does no extra work.
2. A registry added to the ux-icons registry chain serves `sdc:…` names from the component's folder, falling back to the global icon. Every other name passes through untouched.
3. Because the name is unique per component, the ux-icons cache keeps two components' `arrow.svg` apart, and everything ux-icons applies around a registry still applies: the icon cache, `ignore_not_found`, `default_icon_attributes`.
4. On ux-icons 3, component icons are added to the icon cache warmer (`cache:warmup`, `ux:icons:warm-cache`). On ux-icons 2, a component icon is cached on its first rendering.

The prefix `sdc` is reserved for component icons; do not name an icon set `sdc`. Icon file names follow the ux-icons rules: lowercase letters, digits and dashes.

See [Configuration](configuration.md) for the option reference.
