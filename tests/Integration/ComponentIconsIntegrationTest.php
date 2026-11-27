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

use Symfony\UX\Icons\IconFinderInterface;
use Symfony\UX\Icons\UXIconsBundle;
use Twig\Environment;

class ComponentIconsIntegrationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        if (!class_exists(UXIconsBundle::class)) {
            $this->markTestSkipped('symfony/ux-icons is not installed.');
        }
    }

    public function testComponentTemplateUsesItsOwnIcons(): void
    {
        $html = $this->render();

        foreach (['tag', 'func', 'named'] as $testId) {
            $this->assertStringContainsString('LOCAL-ARROW', $this->part($html, $testId), $testId);
        }

        // attributes passed in the template still apply
        $this->assertStringContainsString('class="big"', $this->part($html, 'tag'));
        $this->assertStringContainsString('class="small"', $this->part($html, 'func'));
        $this->assertStringContainsString('aria-hidden="true"', $this->part($html, 'func'));
    }

    public function testComponentFallsBackToTheGlobalIcon(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('GLOBAL-STAR', $this->part($html, 'fallback'));
    }

    public function testPrefixedAliasedAndDynamicNamesStayGlobal(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('GLOBAL-MDI-HOME', $this->part($html, 'prefixed'));
        $this->assertStringContainsString('GLOBAL-ARROW', $this->part($html, 'alias'));
        $this->assertStringContainsString('GLOBAL-ARROW', $this->part($html, 'dynamic'));
    }

    public function testTemplatesOutsideTheComponentsDirectoryAreLeftAlone(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('GLOBAL-ARROW', $this->part($html, 'outside'));
        $this->assertStringContainsString('GLOBAL-ARROW', $this->part($html, 'outside-tag'));
    }

    public function testAnotherComponentOrTemplateCanUseTheFullName(): void
    {
        $html = $this->render('component_icons_full_name.html.twig');

        $this->assertStringContainsString('LOCAL-ARROW', $this->part($html, 'borrowed-tag'));
        $this->assertStringContainsString('LOCAL-ARROW', $this->part($html, 'borrowed-func'));
        $this->assertStringContainsString('LOCAL-ARROW', $this->part($html, 'outside-full'));
    }

    public function testNameIsRewrittenInTheCompiledTemplate(): void
    {
        self::bootKernel();
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        $source = $twig->getLoader()->getSourceContext('IconDemo/CardWithIcon.html.twig');
        $compiled = $twig->compileSource($source);

        $this->assertStringContainsString('"sdc:icon-demo:arrow"', $compiled);
        $this->assertStringContainsString('"sdc:icon-demo:star"', $compiled);
        $this->assertStringContainsString('"mdi:home"', $compiled);
        $this->assertStringContainsString('"go"', $compiled);
    }

    public function testWorksInTheDevEnvironment(): void
    {
        self::bootKernel(['environment' => 'dev']);
        $html = self::getContainer()->get('twig')->render('component_icons.html.twig');

        $this->assertStringContainsString('LOCAL-ARROW', $this->part($html, 'tag'));
        $this->assertStringContainsString('GLOBAL-ARROW', $this->part($html, 'outside'));
    }

    public function testImportCommandFindsTheComponentDirectoryByName(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->assertTrue($container->has('ux_sdc.icons.command.import'));

        $command = $container->get('ux_sdc.icons.command.import');
        $directory = (fn (string $component) => $this->componentDirectory($component))->call($command, 'CardWithIcon');

        $this->assertSame(realpath(__DIR__ . '/Fixtures/Component/IconDemo'), $directory);
    }

    public function testCanBeDisabled(): void
    {
        self::bootKernel(['configs' => ['icons' => false]]);
        $container = self::getContainer();

        $this->assertFalse($container->has('ux_sdc.icons.registry'));

        $html = $container->get('twig')->render('component_icons.html.twig');
        $this->assertStringContainsString('GLOBAL-ARROW', $this->part($html, 'func'));
    }

    public function testCacheWarmerFindsComponentIcons(): void
    {
        if (!interface_exists(IconFinderInterface::class)) {
            $this->markTestSkipped('Icon finders are pluggable from ux-icons 3 on.');
        }

        self::bootKernel();
        $names = iterator_to_array(self::getContainer()->get('.ux_icons.icon_finder')->icons(), false);

        $this->assertContains('sdc:icon-demo:arrow', $names);
    }

    private function render(string $template = 'component_icons.html.twig'): string
    {
        self::bootKernel();

        return self::getContainer()->get('twig')->render($template);
    }

    private function part(string $html, string $testId): string
    {
        $this->assertMatchesRegularExpression('#data-testid="'.$testId.'">(.*?)</span>#s', $html);
        preg_match('#data-testid="'.$testId.'">(.*?)</span>#s', $html, $m);

        return $m[1];
    }
}
