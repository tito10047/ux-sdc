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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Tito10047\UX\Sdc\DataCollector\RequestTrace;
use Tito10047\UX\Sdc\DataCollector\SdcDataCollector;
use Tito10047\UX\Sdc\EventListener\AssetResponseListener;
use Tito10047\UX\Sdc\Tests\Integration\Fixtures\Component\Profiler\Card;
use Tito10047\UX\Sdc\Tests\Integration\Fixtures\Component\Preload\Hero;

/**
 * The web profiler panel, through the whole kernel: a request is profiled, its
 * profile loaded back from the profiler storage, and the panel and the toolbar
 * rendered by WebProfilerBundle.
 */
final class ProfilerFunctionalTest extends FunctionalTestCase
{
    /**
     * @return iterable<string, array{bool}>
     */
    public static function provideMetadataCache(): iterable
    {
        yield 'metadata cache' => [true];
        yield 'runtime metadata' => [false];
    }

    #[DataProvider('provideMetadataCache')]
    public function testCollectsTheComponentsAndTheAssetsOfAPage(bool $metadataCache): void
    {
        $client = self::createProfiledClient(['metadata_cache' => $metadataCache]);

        $client->request('GET', '/page/profiler.html.twig');

        self::assertResponseIsSuccessful();
        $collector = self::collector($client->getProfile());

        $this->assertSame(RequestTrace::OUTCOME_INJECTED, $collector->getOutcome());

        $components = array_column($collector->getComponents(), null, 'name');
        $this->assertSame(['Profiler:Card', 'Anonymous:Notice:Notice', 'Anonymous:Icon:Icon', 'Preload:Hero'], array_keys($components));
        $this->assertSame(Card::class, $components['Profiler:Card']['class']);
        $this->assertSame('Profiler/Card.html.twig', $components['Profiler:Card']['template']);
        $this->assertSame(2, $components['Profiler:Card']['render_count']);
        $this->assertSame(
            [
                'Profiler/Card.css' => 'discovered',
                'Profiler/card-behaviour.js' => '#[AsSdcComponent]',
                'Profiler/missing.css' => '#[Asset]',
            ],
            self::sources($components['Profiler:Card']['assets']),
        );
        $this->assertNull($components['Anonymous:Notice:Notice']['class']);
        $this->assertSame(Hero::class, $components['Preload:Hero']['class']);
        $this->assertSame(5, $collector->getRenderCount());

        // Card asks for its three assets twice; the page loads each once.
        $this->assertSame(12, $collector->getAssetRequestCount());
        $this->assertSame(9, $collector->getEmittedAssetCount());

        $assets = array_column($collector->getAssets(), null, 'path');
        $this->assertSame(
            [
                'Profiler/missing.css' => ['<link rel="stylesheet">', true],
                'Preload/hero.avif' => ['<link rel="preload">', true],
                'Profiler/card-behaviour.js' => ['<script type="module">', false],
                'Profiler/Card.css' => ['<link rel="stylesheet">', true],
                'Anonymous/Notice/Notice.css' => ['<link rel="stylesheet">', true],
                'Anonymous/Notice/Notice.js' => ['<script type="module">', false],
                'Anonymous/Icon/Icon.css' => ['<link rel="stylesheet">', true],
                'Preload/inter.woff2' => ['<link rel="preload">', true],
                'Preload/Hero.css' => ['<link rel="stylesheet">', true],
            ],
            array_map(static fn (array $asset): array => [$asset['emitted_as'], $asset['link_header']], $assets),
        );
        $this->assertSame(['Profiler:Card'], $assets['Profiler/Card.css']['requested_by']);
        $this->assertStringStartsWith('/assets/Profiler/Card-', (string) $assets['Profiler/Card.css']['public_path']);
        $this->assertSame('Profiler/missing.css', $assets['Profiler/missing.css']['public_path'], 'Not in the asset map, linked as it is.');
        $this->assertSame(['fetchpriority' => 'high'], $assets['Preload/hero.avif']['attributes']);

        $this->assertSame([], $collector->getDroppedAssets());
        $this->assertSame(
            ['The asset "Profiler/missing.css" is not in the asset map, so it is linked as it is and will most likely not load. Reference it by its AssetMapper logical path (see "bin/console debug:asset-map"), or by a URL.'],
            array_column($collector->getProblems(), 'message'),
        );
    }

    public function testListsTheAssetsOfAFragmentAsTheHeaderCarriesThem(): void
    {
        $client = self::createProfiledClient();

        $client->request('GET', '/page/fragment.html.twig', server: ['HTTP_TURBO_FRAME' => 'fragment']);

        self::assertResponseIsSuccessful();
        $collector = self::collector($client->getProfile());

        $this->assertSame(RequestTrace::OUTCOME_FRAGMENT, $collector->getOutcome());
        $this->assertSame(
            ['X-SDC-Assets: css', 'X-SDC-Assets: module'],
            array_column($collector->getAssets(), 'emitted_as'),
        );
        $this->assertSame(\strlen((string) $client->getResponse()->headers->get(AssetResponseListener::HEADER)), $collector->getHeaderSize());
        $this->assertSame([], $collector->getProblems());
    }

    public function testReportsAPageWithoutThePlaceholder(): void
    {
        $client = self::createProfiledClient();

        $client->request('GET', '/page/no_placeholder.html.twig');

        $collector = self::collector($client->getProfile());

        $this->assertSame(RequestTrace::OUTCOME_MISSING_PLACEHOLDER, $collector->getOutcome());
        $this->assertCount(1, $collector->getProblems());
        $this->assertStringContainsString('Call {{ render_component_assets() }} inside the <head>', $collector->getProblems()[0]['message']);
    }

    public function testReportsAnImportMapAfterTheModuleScripts(): void
    {
        $client = self::createProfiledClient();

        $client->request('GET', '/page/esm_importmap_after.html.twig');

        $problems = array_column(self::collector($client->getProfile())->getProblems(), 'message');
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('before its import map', $problems[0]);
    }

    /**
     * A sub-request has a profile of its own, but its components land in the
     * registry of the main request and their assets in the main response.
     */
    public function testListsTheComponentsOfASubRequestWithTheMainRequest(): void
    {
        $client = self::createProfiledClient();

        $client->request('GET', '/page/profiler_sub_request.html.twig');

        self::assertResponseIsSuccessful();
        $profile = $client->getProfile();
        $collector = self::collector($profile);

        $this->assertSame(RequestTrace::OUTCOME_INJECTED, $collector->getOutcome());
        $this->assertSame(['AutoDiscovery:AutoDiscoveryComponent'], array_column($collector->getComponents(), 'name'));
        $this->assertCount(2, $collector->getAssets());

        $this->assertCount(1, $profile->getChildren());
        $subRequest = self::collector($profile->getChildren()[0]);
        $this->assertSame(RequestTrace::OUTCOME_SUB_REQUEST, $subRequest->getOutcome());
        $this->assertSame([], $subRequest->getComponents());
        $this->assertSame([], $subRequest->getAssets());
    }

    public function testRendersThePanelAndTheToolbar(): void
    {
        $client = self::createProfiledClient();

        $client->request('GET', '/page/profiler.html.twig');
        $token = $client->getResponse()->headers->get('X-Debug-Token');
        $this->assertNotNull($token);

        $client->request('GET', '/_profiler/'.$token.'?panel='.SdcDataCollector::NAME);

        self::assertResponseIsSuccessful();
        $panel = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('<h2>Component assets</h2>', $panel);
        $this->assertStringContainsString('Profiler:Card', $panel);
        $this->assertStringContainsString(Card::class, $panel);
        $this->assertStringContainsString('anonymous component', $panel);
        $this->assertStringContainsString('&lt;script type=&quot;module&quot;&gt;', $panel);
        $this->assertStringContainsString('The asset &quot;Profiler/missing.css&quot; is not in the asset map', $panel);
        $this->assertStringContainsString('label-status-warning', $panel);

        $client->request('GET', '/_wdt/'.$token);

        self::assertResponseIsSuccessful();
        $toolbar = (string) $client->getResponse()->getContent();
        $this->assertMatchesRegularExpression('/sf-toolbar-block-ux_sdc sf-toolbar-status-yellow/', $toolbar);
        $this->assertStringContainsString('<b>Components</b>', $toolbar);
        // Each number with its label, as TwigComponent's "5 in 3 ms": not "4 / 9 assets", which reads as 4 of 9.
        $this->assertMatchesRegularExpression('#<span class="sf-toolbar-value">4</span>\s*<span class="sf-toolbar-label">components</span>#', $toolbar);
        $this->assertMatchesRegularExpression('#<span class="sf-toolbar-value">9</span>\s*<span class="sf-toolbar-label">assets</span>#', $toolbar);
    }

    /**
     * The exception page replaced the page whose component failed: the panel
     * still lists the components that rendered, and the assets the failure
     * discarded.
     */
    public function testShowsWhatTheFailureOfARequestDiscarded(): void
    {
        // The test logger keeps the log of the exception off the output.
        $client = self::createProfiledClient(compilerPasses: [new RegisterTestLoggerPass()]);

        $client->request('GET', '/page/failing.html.twig');

        self::assertResponseStatusCodeSame(500);
        self::assertResponseNotHasHeader(AssetResponseListener::HEADER);
        $collector = self::collector($client->getProfile());
        $this->assertSame(['AutoDiscovery:AutoDiscoveryComponent', 'Failing:Broken'], array_column($collector->getComponents(), 'name'));
        $this->assertSame(
            [
                ['path' => 'AutoDiscovery/AutoDiscoveryComponent.css', 'type' => 'css'],
                ['path' => 'AutoDiscovery/AutoDiscoveryComponent.js', 'type' => 'js'],
                ['path' => 'Failing/Broken.css', 'type' => 'css'],
            ],
            $collector->getDiscardedAssets(),
        );
        $this->assertSame([], $collector->getAssets());
        $this->assertSame([], $collector->getProblems());

        $token = $client->getResponse()->headers->get('X-Debug-Token');
        $client->request('GET', '/_profiler/'.$token.'?panel='.SdcDataCollector::NAME);

        self::assertResponseIsSuccessful();
        $panel = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Assets of the page that failed', $panel);
        $this->assertStringContainsString('Failing/Broken.css', $panel);
    }

    public function testRendersThePanelOfAPageWithoutComponents(): void
    {
        $client = self::createProfiledClient();

        $client->request('GET', '/page/plain.html.twig');
        $token = $client->getResponse()->headers->get('X-Debug-Token');

        $client->request('GET', '/_profiler/'.$token.'?panel='.SdcDataCollector::NAME);

        self::assertResponseIsSuccessful();
        $this->assertStringContainsString('No component with assets was rendered.', (string) $client->getResponse()->getContent());

        $client->request('GET', '/_wdt/'.$token);

        $this->assertStringNotContainsString('sf-toolbar-block-ux_sdc', (string) $client->getResponse()->getContent());
    }

    /**
     * The trace belongs to one request; a long-running worker serves the next
     * one with the same container.
     */
    public function testTheTraceIsResetBetweenRequests(): void
    {
        $client = self::createProfiledClient();
        $client->disableReboot();

        $client->request('GET', '/page/profiler.html.twig');
        $client->request('GET', '/page/plain.html.twig');

        $collector = self::collector($client->getProfile());
        $this->assertSame([], $collector->getComponents());
        $this->assertSame([], $collector->getAssets());

        $trace = self::getContainer()->get(RequestTrace::class);
        $this->assertInstanceOf(RequestTrace::class, $trace);
        self::getContainer()->get('services_resetter')->reset();
        $this->assertSame([], $trace->getRenders());
    }

    /**
     * WebProfilerBundle renders the panel; the profiler itself is
     * FrameworkBundle's, and collects without it.
     */
    public function testCollectsWithoutWebProfilerBundle(): void
    {
        $client = static::createClient(['framework' => ['profiler' => ['enabled' => true, 'collect' => true]]]);

        $client->request('GET', '/page/auto_discovery.html.twig');

        self::assertResponseIsSuccessful();
        $this->assertSame(
            ['AutoDiscovery:AutoDiscoveryComponent'],
            array_column(self::collector($client->getProfile())->getComponents(), 'name'),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideContainersWithoutTheCollector(): iterable
    {
        yield 'debug without a profiler' => [['debug' => true]];
        yield 'profiler without debug' => [['debug' => false, 'framework' => ['profiler' => ['enabled' => true, 'collect' => true]]]];
    }

    /**
     * Nothing records anything outside the profiler: the services that would
     * get the trace get null.
     *
     * @param array<string, mixed> $options
     */
    #[DataProvider('provideContainersWithoutTheCollector')]
    public function testTheCollectorAndTheTraceExistOnlyInADebugContainerWithAProfiler(array $options): void
    {
        $client = static::createClient($options);

        $client->request('GET', '/page/auto_discovery.html.twig');

        self::assertResponseIsSuccessful();
        $container = self::getContainer();
        $this->assertFalse($container->has(SdcDataCollector::class));
        $this->assertFalse($container->has(RequestTrace::class));
        $this->assertTrue($container->has(AssetResponseListener::class));
    }

    /**
     * @param array<string, mixed>        $configs
     * @param list<CompilerPassInterface> $compilerPasses
     */
    private static function createProfiledClient(array $configs = [], array $compilerPasses = []): KernelBrowser
    {
        $routing = \dirname((string) (new \ReflectionClass(WebProfilerBundle::class))->getFileName()).'/Resources/config/routing/';
        // PHP route files since Symfony 7.3, XML before.
        $extension = is_file($routing.'profiler.php') ? 'php' : 'xml';

        return static::createClient([
            'configs' => $configs,
            'compiler_passes' => $compilerPasses,
            'framework' => ['profiler' => ['enabled' => true, 'collect' => true]],
            'bundles' => [new WebProfilerBundle()],
            'route_imports' => [
                ['@WebProfilerBundle/Resources/config/routing/wdt.'.$extension, null, '/_wdt'],
                ['@WebProfilerBundle/Resources/config/routing/profiler.'.$extension, null, '/_profiler'],
            ],
        ]);
    }

    private static function collector(Profile|false|null $profile): SdcDataCollector
    {
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector(SdcDataCollector::NAME);
        self::assertInstanceOf(SdcDataCollector::class, $collector);

        return $collector;
    }

    /**
     * @param list<array{path: string, source: string}> $assets
     *
     * @return array<string, string> path => source, sorted by path
     */
    private static function sources(array $assets): array
    {
        $sources = array_column($assets, 'source', 'path');
        ksort($sources);

        return $sources;
    }
}
