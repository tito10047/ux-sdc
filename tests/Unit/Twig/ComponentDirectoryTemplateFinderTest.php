<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Unit\Twig;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\UX\TwigComponent\ComponentTemplateFinderInterface;
use Tito10047\UX\Sdc\Twig\ComponentDirectoryTemplateFinder;
use Twig\Loader\FilesystemLoader;

final class ComponentDirectoryTemplateFinderTest extends TestCase
{
    /** The component directory: Twig path of the main namespace and of @component, as SdcExtension registers it. */
    private const COMPONENTS_DIR = __DIR__ . '/../Service/Fixtures';

    /** Another Twig path of the main namespace, which is not the component directory. */
    private const TEMPLATES_DIR = __DIR__ . '/../../Integration/Fixtures/templates';

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function names(): iterable
    {
        yield 'named after its path' => ['components:Notice', 'components/Notice.html.twig'];
        yield 'index of a directory' => ['components:Panel', 'components/Panel/index.html.twig'];
        yield 'trailing whitespace' => ['components:Notice ', 'components/Notice.html.twig'];
        // A component class takes its name before the finder is asked; a class that is none does not.
        yield 'template of a component class, next to it' => ['Alert:Alert', null];
        yield 'page of a controller, next to it' => ['Homepage:HomepageAction', null];
        yield 'only in another Twig path' => ['base', null];
        yield 'nowhere' => ['Missing', null];
    }

    #[DataProvider('names')]
    public function testFindsTemplatesOfTheComponentDirectory(string $name, ?string $expected): void
    {
        $this->assertSame($expected, $this->createFinder(null)->findAnonymousComponentTemplate($name));
    }

    public function testTwigComponentsKeepsPrecedence(): void
    {
        $this->assertSame('components/Alert.html.twig', $this->createFinder('components/Alert.html.twig')->findAnonymousComponentTemplate('Alert:Alert'));
    }

    private function createFinder(?string $innerTemplate): ComponentDirectoryTemplateFinder
    {
        $loader = new FilesystemLoader();
        $loader->addPath(self::COMPONENTS_DIR);
        $loader->addPath(self::TEMPLATES_DIR);
        $loader->addPath(self::COMPONENTS_DIR, 'component');

        $inner = new class ($innerTemplate) implements ComponentTemplateFinderInterface {
            public function __construct(private readonly ?string $template)
            {
            }

            public function findAnonymousComponentTemplate(string $name): ?string
            {
                return $this->template;
            }
        };

        return new ComponentDirectoryTemplateFinder($inner, $loader, self::COMPONENTS_DIR);
    }
}
