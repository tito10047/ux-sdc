<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\UX\TwigComponent\Twig\ComponentRuntime;
use Tito10047\UX\Sdc\Tests\Integration\Fixtures\RendererComponent\IconBundle;
use Tito10047\UX\Sdc\Tests\Integration\Fixtures\RendererComponent\IconComponent;
use Tito10047\UX\Sdc\Tests\Integration\Fixtures\RendererComponent\IconRuntime;
use Twig\Environment;

/**
 * `debug:sdc` in an application with a component Twig Components renders with
 * a service rather than a template, registered the way symfony/ux-icons
 * registers `UX:Icon` (Fixtures/RendererComponent/IconBundle). The application
 * has no other component, so `--strict` sees that one alone.
 *
 * Twig Components takes such renderers from 2.21 on: with 2.20 the component
 * is rendered with a template, and a missing one is a problem.
 */
final class DebugSdcRendererComponentTest extends IntegrationTestCase
{
    /**
     * @return iterable<string, array{bool}>
     */
    public static function metadataCache(): iterable
    {
        yield 'metadata cache' => [true];
        yield 'no metadata cache' => [false];
    }

    /**
     * The fixture is what it emulates: the component renders without a
     * template, and without the render events the bundle listens to.
     */
    public function testTheComponentRendersWithoutATemplate(): void
    {
        self::skipWithoutRenderers();

        self::bootKernel($this->options(false));
        $twig = self::getContainer()->get('twig');
        $this->assertInstanceOf(Environment::class, $twig);

        $this->assertSame(
            '<svg data-icon="star"></svg><svg data-icon="heart"></svg>',
            $twig->createTemplate('{{ component("UX:Icon", {name: "star"}) }}<twig:UX:Icon name="heart" />')->render(),
        );
    }

    #[DataProvider('metadataCache')]
    public function testReportsTheRendererInsteadOfAMissingTemplate(bool $metadataCache): void
    {
        self::skipWithoutRenderers();

        $tester = $this->execute($metadataCache, ['--format' => 'json']);
        $tester->assertCommandIsSuccessful();

        $output = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertIsArray($output);
        $this->assertIsArray($output['components']);
        $this->assertSame(['UX:Icon'], array_column($output['components'], 'name'));

        $icon = $output['components'][0];
        $this->assertIsArray($icon);
        $this->assertSame(IconComponent::class, $icon['class']);
        $this->assertSame(['name' => IconRuntime::class, 'origin' => 'renderer', 'found' => null], $icon['template']);
        $this->assertSame([], $icon['assets']);
        $this->assertSame(['name' => null, 'set_by_component' => false, 'used' => false, 'registered' => null], $icon['stimulus_controller']);
        $this->assertSame([], $icon['problems']);
    }

    #[DataProvider('metadataCache')]
    public function testStrictSucceeds(bool $metadataCache): void
    {
        self::skipWithoutRenderers();

        $tester = $this->execute($metadataCache, ['--strict' => true]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        $this->assertMatchesRegularExpression('~^\s+UX:Icon\s+' . preg_quote(IconRuntime::class, '~') . ' \(renderer\)\s+-\s+-\s+-\s+-\s*$~m', $display);
        $this->assertStringContainsString('[OK] No problems found.', $display);
    }

    /**
     * Twig Components 2.20 ignores the tag and looks for the template, which
     * is missing: that is a problem, the renderer is not taken for one.
     */
    public function testAMissingTemplateIsAProblemWhenTwigComponentsTakesNoRenderers(): void
    {
        if (self::twigComponentsTakesRenderers()) {
            self::markTestSkipped('Twig Components 2.21+ renders the component with the renderer.');
        }

        $tester = $this->execute(false, ['--strict' => true]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('The component has no template', $tester->getDisplay());
    }

    private static function skipWithoutRenderers(): void
    {
        if (!self::twigComponentsTakesRenderers()) {
            self::markTestSkipped('Twig Components 2.20 renders every component with a template.');
        }
    }

    /**
     * Whether the Twig runtime of Twig Components (2.21+) gets the services of
     * the `ux.twig_component.twig_renderer` tag.
     */
    private static function twigComponentsTakesRenderers(): bool
    {
        return (new \ReflectionClass(ComponentRuntime::class))->getConstructor()?->getNumberOfParameters() > 1;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function execute(bool $metadataCache, array $input): CommandTester
    {
        self::ensureKernelShutdown();
        self::bootKernel($this->options($metadataCache));
        $this->assertNotNull(self::$kernel);

        $tester = new CommandTester((new Application(self::$kernel))->find('debug:sdc'));
        $tester->execute($input, ['decorated' => false]);

        return $tester;
    }

    /**
     * @return array<string, mixed>
     */
    private function options(bool $metadataCache): array
    {
        $directory = '%kernel.project_dir%/tests/Integration/Fixtures/RendererComponent';

        return [
            'configs' => [
                'metadata_cache' => $metadataCache,
                // A vendor class, as UXIconComponent is: outside the namespace of the application's components.
                'component_namespace' => null,
                'ux_components_dir' => $directory,
            ],
            // No template, so no anonymous component.
            'twig' => ['default_path' => $directory],
            // What the recipe of Twig Components configures, without which 3.x does not boot.
            'twig_component' => ['defaults' => ['App\\Twig\\Components\\' => 'components/']],
            'bundles' => [new IconBundle()],
        ];
    }
}
