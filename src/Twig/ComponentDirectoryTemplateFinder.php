<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Twig;

use Symfony\UX\TwigComponent\ComponentTemplateFinderInterface;
use Tito10047\UX\Sdc\DependencyInjection\SdcExtension;
use Twig\Loader\LoaderInterface;

/**
 * Finds anonymous components in `ux_sdc.ux_components_dir` too, named the way
 * a component class there is: `Alert/Alert.html.twig` is `<twig:Alert:Alert>`,
 * like `App\Component\Alert\Alert` with `App\Component\` as the component
 * namespace.
 *
 * Decorates the template finder of Twig Components, which looks anonymous
 * components up in `twig_component.anonymous_template_directory` (and in the
 * `components/` directory of a bundle) only. Those keep precedence: a template
 * is looked up here only when Twig Components finds none, and a component
 * class of the same name wins over both, as it always does.
 *
 * As Twig Components does from 2.32 on, `Alert.html.twig` is tried first, then
 * `Alert/index.html.twig`.
 *
 * The template is looked up in the component directory alone, through the
 * namespace SdcExtension registers it under, and named without that namespace,
 * as a template next to a component class is: the component directory is the
 * first Twig path of the main namespace, so the name leads Twig to the same file.
 *
 * @internal
 */
final class ComponentDirectoryTemplateFinder implements ComponentTemplateFinderInterface
{
    public function __construct(
        private readonly ComponentTemplateFinderInterface $inner,
        private readonly LoaderInterface $loader,
    ) {
    }

    public function findAnonymousComponentTemplate(string $name): ?string
    {
        $template = $this->inner->findAnonymousComponentTemplate($name);
        if (null !== $template) {
            return $template;
        }

        $path = rtrim(str_replace(':', '/', $name));

        foreach ([$path . '.html.twig', $path . '/index.html.twig'] as $candidate) {
            if ($this->loader->exists('@' . SdcExtension::TWIG_NAMESPACE . '/' . $candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
