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
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Tester\CommandCompletionTester;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\UX\StimulusBundle\StimulusBundle;
use Tito10047\UX\Sdc\Tests\Integration\Fixtures\DebugSdc\Alert\Alert;
use Tito10047\UX\Sdc\Tests\Integration\Fixtures\DebugSdc\Broken\Broken;
use Tito10047\UX\Sdc\Tests\Integration\Fixtures\Library\Badge\Badge;

/**
 * `debug:sdc` over a component directory of its own (Fixtures/DebugSdc): one
 * component without a problem, one per problem the command finds, and a
 * component outside ux_components_dir whose files AssetMapper does not serve.
 * Anonymous components: `Notice:Notice` in the component directory, `Card` in
 * the anonymous template directory of Twig Components under a Twig default
 * path of its own (Fixtures/DebugSdcTemplates), whose CSS AssetMapper does not
 * serve, and a template there that a component class shadows (`Alert:Alert`).
 *
 * Every case runs with both metadata pipelines: they must describe the
 * components the same way.
 *
 * StimulusBundle is optional (CI runs the suite without it): when it is not
 * installed, whether a Stimulus controller exists is not known, so it is
 * reported as such and never flagged.
 */
final class DebugSdcCommandTest extends IntegrationTestCase
{
    private const NAMESPACE = 'Tito10047\\UX\\Sdc\\Tests\\Integration\\Fixtures\\DebugSdc';

    /**
     * @return iterable<string, array{bool}>
     */
    public static function metadataCache(): iterable
    {
        yield 'metadata cache' => [true];
        yield 'no metadata cache' => [false];
    }

    #[DataProvider('metadataCache')]
    public function testDescribesAComponentWithoutProblems(bool $metadataCache): void
    {
        $alert = $this->json($metadataCache)['Alert:Alert'];

        $assetMapper = self::getContainer()->get(AssetMapperInterface::class);
        $this->assertInstanceOf(AssetMapperInterface::class, $assetMapper);

        $this->assertSame(Alert::class, $alert['class']);
        $this->assertSame(['name' => 'Alert/Alert.html.twig', 'origin' => 'co-located', 'found' => true], $alert['template']);
        $this->assertSame([
            [
                'path' => 'Alert/Alert.woff2',
                'public_path' => $assetMapper->getPublicPath('Alert/Alert.woff2'),
                'type' => 'font',
                'source' => '#[Asset]',
                'priority' => 0,
                'attributes' => [],
                'mapped' => true,
            ],
            [
                'path' => 'Alert/Alert.css',
                'public_path' => $assetMapper->getPublicPath('Alert/Alert.css'),
                'type' => 'css',
                'source' => 'discovered',
                'priority' => 0,
                'attributes' => [],
                'mapped' => true,
            ],
            [
                'path' => 'Alert/Alert.js',
                'public_path' => $assetMapper->getPublicPath('Alert/Alert.js'),
                'type' => 'js',
                'source' => 'discovered',
                'priority' => 0,
                'attributes' => [],
                'mapped' => true,
            ],
        ], $alert['assets']);
        $this->assertSame(['name' => 'Alert--Alert', 'set_by_component' => false, 'used' => true, 'registered' => self::hasStimulusBundle() ? true : null], $alert['stimulus_controller']);
        $this->assertSame([['name' => 'debug_sdc_alert', 'path' => '/debug-sdc/alert']], $alert['routes']);
        $this->assertSame([], $alert['problems']);
    }

    /**
     * An asset path the asset map does not know is flagged, a URL is not. A
     * font is preloaded, which is no problem (before 1.0 it was never loaded).
     */
    #[DataProvider('metadataCache')]
    public function testFlagsAssetsThatDoNotLoad(bool $metadataCache): void
    {
        $broken = $this->json($metadataCache)['Broken:Broken'];

        $this->assertSame(Broken::class, $broken['class']);
        $this->assertSame([
            ['https://cdn.example.com/broken.css', 'https://cdn.example.com/broken.css', 'css', '#[Asset]', null],
            ['https://cdn.example.com/broken.woff2', 'https://cdn.example.com/broken.woff2', 'font', '#[Asset]', null],
            ['missing/broken.css', null, 'css', '#[AsSdcComponent]', false],
        ], array_map(static fn (array $asset): array => [$asset['path'], $asset['public_path'], $asset['type'], $asset['source'], $asset['mapped']], $broken['assets']));

        $this->assertCount(2, $broken['problems']);
        $this->assertStringContainsString('The component has no template', $broken['problems'][0]);
        $this->assertStringContainsString('"missing/broken.css" (#[AsSdcComponent]) is not in the asset map', $broken['problems'][1]);
        $this->assertSame(['origin' => 'default', 'found' => false], array_intersect_key($broken['template'], ['origin' => true, 'found' => true]));
    }

    #[DataProvider('metadataCache')]
    public function testFlagsAnExplicitTemplateThatDoesNotExist(bool $metadataCache): void
    {
        $explicit = $this->json($metadataCache)['Explicit:Explicit'];

        $this->assertSame(['name' => 'debug_sdc/missing.html.twig', 'origin' => 'explicit', 'found' => false], $explicit['template']);
        $this->assertSame(['The template "debug_sdc/missing.html.twig" named in the registration of the component does not exist.'], $explicit['problems']);
    }

    /**
     * A template that uses `controller` needs a controller of that name; one
     * that only has a key or an attribute of that name does not.
     */
    #[DataProvider('metadataCache')]
    public function testFlagsAStimulusControllerTheTemplateUsesButDoesNotExist(bool $metadataCache): void
    {
        if (!self::hasStimulusBundle()) {
            self::markTestSkipped('Whether a controller exists is only known from StimulusBundle; testDoesNotCheckStimulusControllersWithoutStimulusBundle covers its absence.');
        }

        $components = $this->json($metadataCache);

        $this->assertSame(['name' => 'Controlled--Controlled', 'set_by_component' => false, 'used' => true, 'registered' => false], $components['Controlled:Controlled']['stimulus_controller']);
        $this->assertCount(1, $components['Controlled:Controlled']['problems']);
        $this->assertStringContainsString('uses the Stimulus controller "Controlled--Controlled", which StimulusBundle does not know', $components['Controlled:Controlled']['problems'][0]);
        $this->assertStringContainsString('"Controlled_controller.js"', $components['Controlled:Controlled']['problems'][0]);

        $this->assertSame(['name' => 'Quiet--Quiet', 'set_by_component' => false, 'used' => false, 'registered' => false], $components['Quiet:Quiet']['stimulus_controller']);
        $this->assertSame([], $components['Quiet:Quiet']['problems']);
    }

    /**
     * Without StimulusBundle the controller name is still reported, as not
     * checked: a template that uses it is not flagged, and the list shows the
     * name only where the template uses it.
     */
    #[DataProvider('metadataCache')]
    public function testDoesNotCheckStimulusControllersWithoutStimulusBundle(bool $metadataCache): void
    {
        if (self::hasStimulusBundle()) {
            self::markTestSkipped('Only without symfony/stimulus-bundle installed, as in the "Tests without LiveComponent" CI job.');
        }

        $components = $this->json($metadataCache);

        $this->assertSame(['name' => 'Controlled--Controlled', 'set_by_component' => false, 'used' => true, 'registered' => null], $components['Controlled:Controlled']['stimulus_controller']);
        $this->assertSame([], $components['Controlled:Controlled']['problems']);
        $this->assertSame(['name' => 'Quiet--Quiet', 'set_by_component' => false, 'used' => false, 'registered' => null], $components['Quiet:Quiet']['stimulus_controller']);
        $this->assertSame([], $components['Quiet:Quiet']['problems']);

        $display = $this->execute($metadataCache, [])->getDisplay();
        $this->assertMatchesRegularExpression('~^\s+Controlled:Controlled\s+Controlled/Controlled\.html\.twig \(co-located\)\s+-\s+-\s+-\s+Controlled--Controlled\s*$~m', $display);
        $this->assertMatchesRegularExpression('~^\s+Quiet:Quiet\s+Quiet/Quiet\.html\.twig \(co-located\)\s+-\s+-\s+-\s+-\s*$~m', $display);

        $tester = $this->execute($metadataCache, ['name' => 'Controlled:Controlled']);
        $tester->assertCommandIsSuccessful();
        $this->assertMatchesRegularExpression('~^\s+Stimulus controller\s+Controlled--Controlled \(not checked without StimulusBundle\)\s*$~m', $tester->getDisplay());
        $this->assertStringContainsString('[OK] No problems found.', $tester->getDisplay());
    }

    /**
     * The files the resolver has to leave out reach the command from both
     * pipelines: with the metadata cache, through `sdc_metadata.php`.
     */
    #[DataProvider('metadataCache')]
    public function testReportsFilesOutsideEveryAssetMapperPath(bool $metadataCache): void
    {
        $badge = $this->json($metadataCache)['LibraryBadge'];

        $this->assertSame(Badge::class, $badge['class']);
        $this->assertSame(['name' => '@ui/Badge/Badge.html.twig', 'origin' => 'co-located', 'found' => true], $badge['template']);
        $this->assertSame([], $badge['assets']);
        $this->assertSame(['name' => null, 'set_by_component' => false, 'used' => false, 'registered' => null], $badge['stimulus_controller'], 'Outside the component namespace.');
        $this->assertCount(2, $badge['problems']);
        $this->assertStringStartsWith('"' . realpath(__DIR__ . '/Fixtures/Library/Badge/Badge.css') . '" next to the class is outside every AssetMapper path (', $badge['problems'][0]);
        $this->assertStringStartsWith('"' . realpath(__DIR__ . '/Fixtures/Library/Badge/Badge.js') . '" next to the class is outside every AssetMapper path (', $badge['problems'][1]);
    }

    /**
     * An anonymous component has its template and the assets next to it; no
     * class, no routes, no Stimulus controller from the bundle.
     */
    #[DataProvider('metadataCache')]
    public function testDescribesAnonymousComponents(bool $metadataCache): void
    {
        $components = $this->json($metadataCache);

        $assetMapper = self::getContainer()->get(AssetMapperInterface::class);
        $this->assertInstanceOf(AssetMapperInterface::class, $assetMapper);

        $notice = $components['Notice:Notice'];
        $this->assertNull($notice['class']);
        $this->assertSame(['name' => 'Notice/Notice.html.twig', 'origin' => 'anonymous', 'found' => true], $notice['template']);
        $this->assertSame([
            [
                'path' => 'Notice/Notice.css',
                'public_path' => $assetMapper->getPublicPath('Notice/Notice.css'),
                'type' => 'css',
                'source' => 'discovered',
                'priority' => 0,
                'attributes' => [],
                'mapped' => true,
            ],
        ], $notice['assets']);
        $this->assertSame(['name' => null, 'set_by_component' => false, 'used' => false, 'registered' => null], $notice['stimulus_controller']);
        $this->assertSame([], $notice['routes']);
        $this->assertSame([], $notice['problems']);

        $card = $components['Card'];
        $this->assertNull($card['class']);
        $this->assertSame(['name' => 'components/Card.html.twig', 'origin' => 'anonymous', 'found' => true], $card['template']);
        $this->assertSame([], $card['assets']);
        $this->assertCount(1, $card['problems']);
        $this->assertStringStartsWith('"' . realpath(__DIR__ . '/Fixtures/DebugSdcTemplates/components/Card.css') . '" next to the template is outside every AssetMapper path (', $card['problems'][0]);

        // components/Alert/Alert.html.twig would be <twig:Alert:Alert>, which is the class.
        $this->assertSame(Alert::class, $components['Alert:Alert']['class']);
    }

    #[DataProvider('metadataCache')]
    public function testShowsAnAnonymousComponentInDetail(bool $metadataCache): void
    {
        $tester = $this->execute($metadataCache, ['name' => 'Notice:Notice']);
        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();

        $this->assertMatchesRegularExpression('/^\s+Class\s+none \(anonymous component\)\s*$/m', $display);
        $this->assertMatchesRegularExpression('~^\s+Template\s+Notice/Notice\.html\.twig \(anonymous\)\s*$~m', $display);
        $this->assertMatchesRegularExpression('~^\s+Routes\s+-\s*$~m', $display);
        $this->assertStringContainsString('[OK] No problems found.', $display);
    }

    #[DataProvider('metadataCache')]
    public function testListsComponentsAndFlagsProblems(bool $metadataCache): void
    {
        $tester = $this->execute($metadataCache, []);

        $tester->assertCommandIsSuccessful('Like every debug:* command, problems do not fail it without --strict.');
        $display = $tester->getDisplay();

        $this->assertMatchesRegularExpression('/^\s+Component\s+Template\s+CSS\s+JS\s+Preload\s+Stimulus controller\s*$/m', $display);
        $this->assertMatchesRegularExpression('~^\s+Alert:Alert\s+Alert/Alert\.html\.twig \(co-located\)\s+Alert/Alert\.css\s+Alert/Alert\.js\s+Alert/Alert\.woff2 \(#\[Asset\]\)\s+Alert--Alert\s*$~m', $display);
        $this->assertMatchesRegularExpression('~^\s+⚠ Broken:Broken\s+⚠ /Broken/Broken\.html\.twig \(default\)\s+https://cdn\.example\.com/broken\.css \(#\[Asset\]\)\s+-\s+https://cdn\.example\.com/broken\.woff2 \(#\[Asset\]\)\s+-\s*$~m', $display);
        $this->assertMatchesRegularExpression('~^\s+⚠ missing/broken\.css \(#\[AsSdcComponent\]\)\s*$~m', $display);
        $this->assertMatchesRegularExpression(self::hasStimulusBundle()
            ? '~^\s+⚠ Controlled:Controlled\s+Controlled/Controlled\.html\.twig \(co-located\)\s+-\s+-\s+-\s+⚠ Controlled--Controlled\s*$~m'
            : '~^\s+Controlled:Controlled\s+Controlled/Controlled\.html\.twig \(co-located\)\s+-\s+-\s+-\s+Controlled--Controlled\s*$~m', $display);
        $this->assertMatchesRegularExpression('~^\s+⚠ Explicit:Explicit\s+⚠ debug_sdc/missing\.html\.twig \(explicit\)~m', $display);
        $this->assertMatchesRegularExpression('~^\s+Quiet:Quiet\s+Quiet/Quiet\.html\.twig \(co-located\)\s+-\s+-\s+-\s+-\s*$~m', $display);
        $this->assertMatchesRegularExpression('~^\s+Notice:Notice\s+Notice/Notice\.html\.twig \(anonymous\)\s+Notice/Notice\.css\s+-\s+-\s+-\s*$~m', $display);
        $this->assertMatchesRegularExpression('~^\s+⚠ Card\s+components/Card\.html\.twig \(anonymous\)\s+-\s+-\s+-\s+-\s*$~m', $display);

        $this->assertStringContainsString('Broken:Broken (' . Broken::class . ')', $display);
        $this->assertStringContainsString('Card (components/Card.html.twig)', $display);
        $this->assertStringNotContainsString('Alert:Alert (' . Alert::class . ')', $display, 'A component without problems has no section.');
        // The missing controller of Controlled:Controlled is one of them only with StimulusBundle.
        $this->assertStringContainsString(\sprintf('[WARNING] %d problems found.', self::hasStimulusBundle() ? 7 : 6), $display);
        $this->assertStringContainsString($metadataCache ? 'Metadata from the build-time cache' : 'Metadata resolved from the files next to each class', $display);
    }

    public function testStrictFailsOnProblemsOnly(): void
    {
        $this->assertSame(Command::FAILURE, $this->execute(true, ['--strict' => true])->getStatusCode());
        $this->assertSame(Command::FAILURE, $this->execute(true, ['--strict' => true, '--format' => 'json'])->getStatusCode());

        $tester = $this->execute(true, ['name' => 'Alert:Alert', '--strict' => true]);
        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('[OK] No problems found.', $tester->getDisplay());
    }

    /**
     * A single match is shown in detail, with the public paths, the source of
     * each asset and the routes.
     */
    #[DataProvider('metadataCache')]
    public function testShowsASingleComponentInDetail(bool $metadataCache): void
    {
        $tester = $this->execute($metadataCache, ['name' => 'Alert:Alert']);
        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();

        $assetMapper = self::getContainer()->get(AssetMapperInterface::class);
        $this->assertInstanceOf(AssetMapperInterface::class, $assetMapper);

        $this->assertMatchesRegularExpression('/^\s+Property\s+Value\s*$/m', $display);
        $this->assertMatchesRegularExpression('/^\s+Class\s+' . preg_quote(Alert::class, '/') . '\s*$/m', $display);
        $this->assertStringContainsString('Alert/Alert.css → ' . $assetMapper->getPublicPath('Alert/Alert.css') . ' (discovered)', $display);
        $this->assertStringContainsString('Alert/Alert.js → ' . $assetMapper->getPublicPath('Alert/Alert.js') . ' (discovered)', $display);
        $this->assertMatchesRegularExpression('~^\s+Preload\s+Alert/Alert\.woff2 → ' . preg_quote((string) $assetMapper->getPublicPath('Alert/Alert.woff2'), '~') . ' \(#\[Asset\]\)\s*$~m', $display);
        $this->assertMatchesRegularExpression('~^\s+Routes\s+debug_sdc_alert \(/debug-sdc/alert\)\s*$~m', $display);
        $this->assertMatchesRegularExpression(self::hasStimulusBundle() ? '/^\s+Stimulus controller\s+Alert--Alert\s*$/m' : '/^\s+Stimulus controller\s+Alert--Alert \(not checked without StimulusBundle\)\s*$/m', $display);
        $this->assertStringContainsString('[OK] No problems found.', $display);
    }

    /**
     * @param list<string> $expected component names
     */
    #[DataProvider('filters')]
    public function testFiltersByNameOrClass(string $filter, array $expected): void
    {
        $tester = $this->execute(false, ['name' => $filter, '--format' => 'json']);
        $tester->assertCommandIsSuccessful();

        $this->assertSame($expected, array_column($this->decode($tester)['components'], 'name'));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function filters(): iterable
    {
        yield 'exact name' => ['Alert:Alert', ['Alert:Alert']];
        yield 'part of a name, any case' => ['alert', ['Alert:Alert']];
        yield 'class' => [Broken::class, ['Broken:Broken']];
        yield 'class with a leading backslash' => ['\\' . Broken::class, ['Broken:Broken']];
        yield 'part of a class' => ['Fixtures\\Library', ['LibraryBadge']];
        yield 'part of several' => ['ro', ['Broken:Broken', 'Controlled:Controlled']];
        yield 'anonymous' => ['notice', ['Notice:Notice']];
    }

    public function testFailsWhenNothingMatches(): void
    {
        $tester = $this->execute(false, ['name' => 'Nope']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('No component matches "Nope".', $tester->getDisplay());
    }

    public function testRejectsAnUnknownFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The format "xml" is not supported. Supported formats are "txt", "json".');

        $this->execute(false, ['--format' => 'xml']);
    }

    public function testCompletesComponentNamesAndFormats(): void
    {
        self::bootKernel($this->options(false));
        $this->assertNotNull(self::$kernel);
        $tester = new CommandCompletionTester((new Application(self::$kernel))->find('debug:sdc'));

        $this->assertSame(['Alert:Alert', 'Broken:Broken', 'Card', 'Controlled:Controlled', 'Explicit:Explicit', 'LibraryBadge', 'Notice:Notice', 'Quiet:Quiet'], $tester->complete(['']));
        $this->assertSame(['txt', 'json'], $tester->complete(['--format', '']));
    }

    private static function hasStimulusBundle(): bool
    {
        return class_exists(StimulusBundle::class);
    }

    /**
     * @return array<string, array<string, mixed>> the components of `--format=json`, by name
     */
    private function json(bool $metadataCache): array
    {
        $tester = $this->execute($metadataCache, ['--format' => 'json']);
        $tester->assertCommandIsSuccessful();

        $output = $this->decode($tester);
        $this->assertSame($metadataCache, $output['metadata_cache']);
        $this->assertSame(['Alert:Alert', 'Broken:Broken', 'Card', 'Controlled:Controlled', 'Explicit:Explicit', 'LibraryBadge', 'Notice:Notice', 'Quiet:Quiet'], array_column($output['components'], 'name'), 'Sorted by name.');

        return array_column($output['components'], null, 'name');
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(CommandTester $tester): array
    {
        $output = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertIsArray($output);

        return $output;
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
        return [
            'configs' => [
                'metadata_cache' => $metadataCache,
                'component_namespace' => self::NAMESPACE,
                'ux_components_dir' => '%kernel.project_dir%/tests/Integration/Fixtures/DebugSdc',
            ],
            'twig' => [
                // Its `components/` directory holds anonymous components.
                'default_path' => '%kernel.project_dir%/tests/Integration/Fixtures/DebugSdcTemplates',
                // A component library of the application: a Twig path, not an AssetMapper path.
                'paths' => ['%kernel.project_dir%/tests/Integration/Fixtures/Library' => 'ui'],
            ],
            'services' => [Badge::class],
            'route_imports' => [['.', 'ux_sdc']],
        ];
    }
}
