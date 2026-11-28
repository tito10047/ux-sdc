<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Unit\DataCollector;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tito10047\UX\Sdc\DataCollector\RequestTrace;
use Tito10047\UX\Sdc\DataCollector\SdcDataCollector;
use Tito10047\UX\Sdc\Response\FragmentAssetHeader;
use Tito10047\UX\Sdc\Response\ResolvedAsset;
use Tito10047\UX\Sdc\Service\ComponentMetadata;

final class SdcDataCollectorTest extends TestCase
{
    private RequestTrace $trace;
    private SdcDataCollector $collector;

    protected function setUp(): void
    {
        $this->trace = new RequestTrace();
        $this->collector = new SdcDataCollector($this->trace);
    }

    public function testIsTheUxSdcPanel(): void
    {
        $this->assertSame('ux_sdc', $this->collector->getName());
        $this->assertSame('@Sdc/data_collector/ux_sdc.html.twig', SdcDataCollector::getTemplate());
    }

    public function testListsTheComponentsWithTheAssetsTheyAskedFor(): void
    {
        $alert = self::metadata([
            self::asset('Alert/Alert.css', 'css', ComponentMetadata::SOURCE_DISCOVERED),
            self::asset('Alert/Alert.js', 'js', ComponentMetadata::SOURCE_DISCOVERED),
        ]);
        $this->trace->componentRendered('Alert', 'App\Component\Alert', 'Alert/Alert.html.twig', $alert);
        $this->trace->componentRendered('Alert', 'App\Component\Alert', 'Alert/Alert.html.twig', $alert);
        $this->trace->componentRendered('Notice', null, 'Notice/Notice.html.twig', self::metadata([
            self::asset('Alert/Alert.css', 'css', ComponentMetadata::SOURCE_ASSET_ATTRIBUTE, 5),
        ]));

        $request = new Request();
        $this->trace->responseHandled($request, RequestTrace::OUTCOME_INJECTED, [], [
            new ResolvedAsset('/assets/Alert/Alert-1a2b.css', 'css', [], 'Alert/Alert.css'),
            new ResolvedAsset('/assets/Alert/Alert-3c4d.js', 'js', [], 'Alert/Alert.js'),
        ]);

        $this->collect($request);

        $this->assertSame(
            [
                [
                    'name' => 'Alert',
                    'class' => 'App\Component\Alert',
                    'template' => 'Alert/Alert.html.twig',
                    'render_count' => 2,
                    'assets' => [
                        ['path' => 'Alert/Alert.css', 'type' => 'css', 'source' => 'discovered', 'priority' => 0],
                        ['path' => 'Alert/Alert.js', 'type' => 'js', 'source' => 'discovered', 'priority' => 0],
                    ],
                    'problems' => [],
                ],
                [
                    'name' => 'Notice',
                    'class' => null,
                    'template' => 'Notice/Notice.html.twig',
                    'render_count' => 1,
                    'assets' => [
                        ['path' => 'Alert/Alert.css', 'type' => 'css', 'source' => '#[Asset]', 'priority' => 5],
                    ],
                    'problems' => [],
                ],
            ],
            $this->collector->getComponents(),
        );
        $this->assertSame(3, $this->collector->getRenderCount());
        $this->assertSame(5, $this->collector->getAssetRequestCount());
        $this->assertSame(2, $this->collector->getEmittedAssetCount());
        $this->assertSame(['Alert', 'Notice'], $this->collector->getAssets()[0]['requested_by']);
        $this->assertSame(['Alert'], $this->collector->getAssets()[1]['requested_by']);
        $this->assertSame([], $this->collector->getProblems());
    }

    public function testTellsHowAPageGotEachAsset(): void
    {
        $request = new Request();
        $this->trace->responseHandled($request, RequestTrace::OUTCOME_INJECTED, [], [
            new ResolvedAsset('/a.css', 'css'),
            new ResolvedAsset('/a.js', 'js'),
            new ResolvedAsset('/legacy.js', 'js', ['type' => false]),
            new ResolvedAsset('/inter.woff2', 'font'),
            new ResolvedAsset('/hero.avif', 'image'),
        ]);

        $this->collect($request);

        $this->assertSame(
            [
                ['<link rel="stylesheet">', true],
                ['<script type="module">', false],
                ['<script>', true],
                ['<link rel="preload">', true],
                ['<link rel="preload">', true],
            ],
            array_map(static fn (array $asset): array => [$asset['emitted_as'], $asset['link_header']], $this->collector->getAssets()),
        );
    }

    public function testTellsHowAFragmentGotEachAsset(): void
    {
        $request = new Request();
        $this->trace->responseHandled($request, RequestTrace::OUTCOME_FRAGMENT, [], [
            new ResolvedAsset('/a.css', 'css'),
            new ResolvedAsset('/a.js', 'js'),
            new ResolvedAsset('/legacy.js', 'js', ['type' => false]),
            new ResolvedAsset('/inter.woff2', 'font'),
        ]);
        $response = new Response();
        $response->headers->set(FragmentAssetHeader::NAME, '{"v":1,"assets":[]}');

        $this->collect($request, $response);

        $this->assertSame(
            ['X-SDC-Assets: css', 'X-SDC-Assets: module', 'X-SDC-Assets: script', null],
            array_column($this->collector->getAssets(), 'emitted_as'),
        );
        $this->assertSame([false, false, false, false], array_column($this->collector->getAssets(), 'link_header'));
        $this->assertSame(3, $this->collector->getEmittedAssetCount());
        $this->assertSame(19, $this->collector->getHeaderSize());
    }

    public function testListsTheAssetsOfAResponseThatGotNone(): void
    {
        $request = new Request();
        $this->trace->responseHandled($request, RequestTrace::OUTCOME_NOT_HTML, [
            ['path' => 'Alert/Alert.css', 'type' => 'css', 'priority' => 0, 'attributes' => ['media' => 'print']],
        ]);

        $this->collect($request);

        $this->assertSame(RequestTrace::OUTCOME_NOT_HTML, $this->collector->getOutcome());
        $this->assertSame(
            [[
                'path' => 'Alert/Alert.css',
                'public_path' => null,
                'type' => 'css',
                'attributes' => ['media' => 'print'],
                'emitted_as' => null,
                'link_header' => false,
                'requested_by' => [],
            ]],
            $this->collector->getAssets(),
        );
        $this->assertSame(0, $this->collector->getEmittedAssetCount());
        $this->assertSame([], $this->collector->getProblems());
    }

    /**
     * The error page replaced the page that failed: the panel lists both
     * pages' components, and what the failure discarded — which is no problem.
     */
    public function testListsTheAssetsAFailedRequestDiscarded(): void
    {
        $request = new Request();
        $this->trace->componentRendered('Broken', 'App\Component\Broken', 'Broken.html.twig', self::metadata([
            self::asset('Broken.css', 'css', ComponentMetadata::SOURCE_DISCOVERED),
        ]));
        $this->trace->requestFailed($request, [['path' => 'Broken.css', 'type' => 'css', 'priority' => 0, 'attributes' => []]]);
        $this->trace->componentRendered('Notice', null, 'Notice.html.twig', self::metadata([
            self::asset('Notice.css', 'css', ComponentMetadata::SOURCE_DISCOVERED),
        ]));
        $this->trace->responseHandled($request, RequestTrace::OUTCOME_INJECTED, [], [new ResolvedAsset('/assets/Notice.css', 'css', [], 'Notice.css')]);

        $this->collect($request, new Response('', 500));

        $this->assertSame([['path' => 'Broken.css', 'type' => 'css']], $this->collector->getDiscardedAssets());
        $this->assertSame(['Broken', 'Notice'], array_column($this->collector->getComponents(), 'name'));
        $this->assertSame(['Notice.css'], array_column($this->collector->getAssets(), 'path'));
        $this->assertSame([], $this->collector->getDroppedAssets());
        $this->assertSame([], $this->collector->getProblems());
    }

    public function testListsNoComponentsForASubRequest(): void
    {
        $this->trace->componentRendered('Alert', 'App\Component\Alert', 'Alert/Alert.html.twig', self::metadata([
            self::asset('Alert/Alert.css', 'css', ComponentMetadata::SOURCE_DISCOVERED),
        ]));
        $request = new Request();
        $this->trace->responseHandled($request, RequestTrace::OUTCOME_SUB_REQUEST);

        $this->collect($request);

        $this->assertSame(RequestTrace::OUTCOME_SUB_REQUEST, $this->collector->getOutcome());
        $this->assertSame([], $this->collector->getComponents());
        $this->assertSame([], $this->collector->getAssets());
        $this->assertSame([], $this->collector->getProblems());
    }

    public function testCollectsAResponseTheBundleDidNotSee(): void
    {
        $this->collect(new Request());

        $this->assertNull($this->collector->getOutcome());
        $this->assertSame([], $this->collector->getAssets());
        $this->assertNull($this->collector->getHeaderSize());
    }

    public function testReportsEveryKindOfProblem(): void
    {
        $this->trace->componentRendered('Card', 'App\Component\Card', 'Card/Card.html.twig', self::metadata(
            [self::asset('Card/Card.css', 'css', ComponentMetadata::SOURCE_DISCOVERED)],
            [['message' => 'The file "{file}" is outside every AssetMapper path.', 'context' => ['file' => '/app/templates/Card.js']]],
        ));

        $request = new Request();
        $this->trace->importMapAfterModules($request);
        $this->trace->responseHandled(
            $request,
            RequestTrace::OUTCOME_MISSING_PLACEHOLDER,
            [
                ['path' => 'Card/Card.css', 'type' => 'css', 'priority' => 0, 'attributes' => []],
                ['path' => 'Card/typo.css', 'type' => 'css', 'priority' => 0, 'attributes' => []],
                ['path' => 'https://cdn.example.com/movie.mp4', 'type' => 'video', 'priority' => 0, 'attributes' => []],
                ['path' => 'https://cdn.example.com/lib', 'type' => '', 'priority' => 0, 'attributes' => []],
            ],
            [
                new ResolvedAsset('/assets/Card/Card-1a2b.css', 'css', [], 'Card/Card.css'),
                new ResolvedAsset('Card/typo.css', 'css'),
            ],
        );

        // Rendered after the response got its assets, in the body of a streamed response.
        $this->trace->componentRendered('Late', 'App\Component\Late', 'Late/Late.html.twig', self::metadata([
            self::asset('Late/Late.css', 'css', ComponentMetadata::SOURCE_DISCOVERED),
        ]));
        $this->trace->componentRendered('Bare', 'App\Component\Bare', 'Bare/Bare.html.twig', self::metadata([]));

        $response = new Response();
        $response->headers->set(FragmentAssetHeader::NAME, str_repeat('x', FragmentAssetHeader::SIZE_WARNING + 1));

        $this->collect($request, $response);

        $this->assertSame(
            [
                [
                    'path' => 'https://cdn.example.com/movie.mp4',
                    'type' => 'video',
                    'reason' => 'its type "video" is none of those the bundle loads (css, js, font, image).',
                ],
                [
                    'path' => 'https://cdn.example.com/lib',
                    'type' => '',
                    'reason' => 'it was registered without a type, and its extension tells none.',
                ],
            ],
            $this->collector->getDroppedAssets(),
        );

        $problems = $this->collector->getProblems();
        $this->assertCount(8, $problems);
        $this->assertStringStartsWith('The page has no placeholder', $problems[0]['message']);
        $this->assertStringContainsString('before its import map', $problems[1]['message']);
        $this->assertStringStartsWith('The asset "Card/typo.css" is not in the asset map', $problems[2]['message']);
        $this->assertStringStartsWith('The asset "https://cdn.example.com/movie.mp4" is not loaded', $problems[3]['message']);
        $this->assertStringStartsWith('The asset "https://cdn.example.com/lib" is not loaded', $problems[4]['message']);
        $this->assertStringStartsWith('The X-SDC-Assets header is 2049 bytes long.', $problems[5]['message']);
        $this->assertSame(['message' => 'The file "/app/templates/Card.js" is outside every AssetMapper path.', 'component' => 'Card'], $problems[6]);
        $this->assertSame('Late', $problems[7]['component']);
        $this->assertStringContainsString('after the response had got its assets', $problems[7]['message']);
        $this->assertNull($problems[0]['component']);
    }

    public function testAUrlOutsideTheAssetMapIsNoProblem(): void
    {
        $request = new Request();
        $this->trace->responseHandled($request, RequestTrace::OUTCOME_INJECTED, [], [
            new ResolvedAsset('https://cdn.example.com/x.css', 'css'),
            new ResolvedAsset('/build/app.js', 'js'),
        ]);

        $this->collect($request);

        $this->assertSame([], $this->collector->getProblems());
    }

    /**
     * The profile is stored serialized, and loaded back to render the panel.
     */
    public function testTheDataSurvivesSerialization(): void
    {
        $this->trace->componentRendered('Alert', 'App\Component\Alert', 'Alert/Alert.html.twig', self::metadata([
            self::asset('Alert/Alert.css', 'css', ComponentMetadata::SOURCE_DISCOVERED),
        ]));
        $request = new Request();
        $this->trace->responseHandled($request, RequestTrace::OUTCOME_INJECTED, [], [
            new ResolvedAsset('/assets/Alert/Alert-1a2b.css', 'css', ['media' => 'print'], 'Alert/Alert.css'),
        ]);
        $this->collect($request);

        $loaded = unserialize(serialize($this->collector));

        $this->assertInstanceOf(SdcDataCollector::class, $loaded);
        $this->assertSame($this->collector->getComponents(), $loaded->getComponents());
        $this->assertSame($this->collector->getAssets(), $loaded->getAssets());
        $this->assertSame(RequestTrace::OUTCOME_INJECTED, $loaded->getOutcome());
    }

    public function testResetForgetsTheData(): void
    {
        $request = new Request();
        $this->trace->responseHandled($request, RequestTrace::OUTCOME_INJECTED, [], [new ResolvedAsset('/a.css', 'css')]);
        $this->collect($request);

        $this->collector->reset();

        $this->assertNull($this->collector->getOutcome());
        $this->assertSame([], $this->collector->getAssets());
        $this->assertSame([], $this->collector->getComponents());
        $this->assertSame([], $this->collector->getProblems());
    }

    private function collect(Request $request, ?Response $response = null): void
    {
        $this->collector->collect($request, $response ?? new Response());
        $this->collector->lateCollect();
    }

    /**
     * @param list<array{path: string, type: string, priority: int, attributes: array<string, mixed>, source: ComponentMetadata::SOURCE_*}> $assets
     * @param list<array{message: string, context: array<string, string>}>                                                               $problems
     */
    private static function metadata(array $assets, array $problems = []): ComponentMetadata
    {
        return new ComponentMetadata($assets, null, $problems);
    }

    /**
     * @param ComponentMetadata::SOURCE_* $source
     *
     * @return array{path: string, type: string, priority: int, attributes: array<string, mixed>, source: ComponentMetadata::SOURCE_*}
     */
    private static function asset(string $path, string $type, string $source, int $priority = 0): array
    {
        return ['path' => $path, 'type' => $type, 'priority' => $priority, 'attributes' => [], 'source' => $source];
    }
}
