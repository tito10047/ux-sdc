<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\DataCollector;

use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;
use Tito10047\UX\Sdc\Response\FragmentAssetHeader;
use Tito10047\UX\Sdc\Response\ResolvedAsset;
use Tito10047\UX\Sdc\Service\AssetPath;
use Tito10047\UX\Sdc\Service\AssetRegistry;
use Tito10047\UX\Sdc\Service\AssetType;

/**
 * The "Components & assets" panel of the web profiler: the components a
 * request rendered and the assets each contributed, what the response got
 * and how, and what went wrong on the way.
 *
 * It reads what the services recorded in RequestTrace, in two steps:
 *
 *  - collect(), on `kernel.response` after AssetResponseListener (the profiler
 *    listens at -100, the bundle at 64): how this response got the assets is
 *    final by then, and only then is the request at hand;
 *  - lateCollect(), on `kernel.terminate`: the components. A streamed
 *    response renders its body — and the components in it — after
 *    `kernel.response`; those are listed too, as having come too late.
 *
 * A sub-request has its own profile, but its components land in the registry
 * of the main request and its assets in the main response, which is where
 * the panel lists them.
 *
 * A request that failed lists the components of the page that failed and
 * those of the error page, and the assets the failure discarded: the error
 * response replaced the page that asked for them.
 *
 * `SdcCollectedAsset` is an asset of the response: the path the component
 * declared, the one the page links to (null when the response got none), how
 * it was emitted (a tag, an `X-SDC-Assets` entry, null for not at all) and
 * whether a `Link: rel=preload` header announced it.
 *
 * @phpstan-import-type SdcAsset from AssetRegistry
 *
 * @phpstan-type SdcCollectedAsset array{path: string, public_path: string|null, type: string, attributes: array<string, mixed>, emitted_as: string|null, link_header: bool, requested_by: list<string>}
 * @phpstan-type SdcCollectedComponent array{name: string, class: string|null, template: string, render_count: int, assets: list<array{path: string, type: string, source: string, priority: int}>, problems: list<string>}
 * @phpstan-type SdcCollectedProblem array{message: string, component: string|null}
 *
 * @internal
 */
final class SdcDataCollector extends AbstractDataCollector implements LateDataCollectorInterface
{
    public const NAME = 'ux_sdc';
    public const TEMPLATE = '@Sdc/data_collector/ux_sdc.html.twig';

    public function __construct(
        private readonly RequestTrace $trace,
    ) {
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $handled = $this->trace->getHandledResponse($request);
        $outcome = $handled['outcome'] ?? null;

        $assets = [];
        $dropped = [];

        if (null !== $handled && \in_array($outcome, [RequestTrace::OUTCOME_NOT_HTML, RequestTrace::OUTCOME_STREAMED], true)) {
            foreach ($handled['registered'] as $asset) {
                $assets[] = $this->asset($asset['path'], null, $asset['type'], $asset['attributes'], null, false);
            }
        } elseif (null !== $handled) {
            foreach ($handled['resolved'] as $asset) {
                $assets[] = $this->resolvedAsset($asset, RequestTrace::OUTCOME_INJECTED === $outcome);
            }
            $dropped = $this->droppedAssets($handled['registered'], $handled['resolved']);
        }

        $header = $response->headers->get(FragmentAssetHeader::NAME);

        $this->data = [
            'outcome' => $outcome,
            'assets' => $assets,
            'dropped' => $dropped,
            'discarded' => array_map(
                static fn (array $asset): array => ['path' => $asset['path'], 'type' => $asset['type']],
                $this->trace->getDiscardedAssets($request),
            ),
            'header_size' => null === $header ? null : \strlen($header),
            'import_map_after_modules' => $this->trace->hasImportMapAfterModules($request),
            'components' => [],
            'late_components' => [],
            'asset_requests' => 0,
            'problems' => [],
        ];
    }

    public function lateCollect(): void
    {
        if (RequestTrace::OUTCOME_SUB_REQUEST !== $this->data['outcome']) {
            $this->collectComponents();
        }

        $this->data['problems'] = $this->problems();
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public static function getTemplate(): string
    {
        return self::TEMPLATE;
    }

    /**
     * @return RequestTrace::OUTCOME_*|null null when the bundle did not see the response
     */
    public function getOutcome(): ?string
    {
        return $this->data['outcome'] ?? null;
    }

    /**
     * @return list<SdcCollectedComponent>
     */
    public function getComponents(): array
    {
        return $this->data['components'] ?? [];
    }

    /**
     * @return list<SdcCollectedAsset>
     */
    public function getAssets(): array
    {
        return $this->data['assets'] ?? [];
    }

    /**
     * The assets of the registry the response does not link to, with why.
     *
     * @return list<array{path: string, type: string, reason: string}>
     */
    public function getDroppedAssets(): array
    {
        return $this->data['dropped'] ?? [];
    }

    /**
     * The assets registered before the request failed, which the error
     * response does not get.
     *
     * @return list<array{path: string, type: string}>
     */
    public function getDiscardedAssets(): array
    {
        return $this->data['discarded'] ?? [];
    }

    /**
     * @return list<SdcCollectedProblem>
     */
    public function getProblems(): array
    {
        return $this->data['problems'] ?? [];
    }

    /**
     * How many assets the renders asked for, counting every time one did:
     * the response loads each asset once.
     */
    public function getAssetRequestCount(): int
    {
        return $this->data['asset_requests'] ?? 0;
    }

    public function getRenderCount(): int
    {
        return array_sum(array_column($this->getComponents(), 'render_count'));
    }

    /**
     * The assets the response loads or preloads.
     */
    public function getEmittedAssetCount(): int
    {
        return \count(array_filter($this->getAssets(), static fn (array $asset): bool => null !== $asset['emitted_as']));
    }

    /**
     * @return int|null the length of the X-SDC-Assets header, null when the response has none
     */
    public function getHeaderSize(): ?int
    {
        return $this->data['header_size'] ?? null;
    }

    private function collectComponents(): void
    {
        $components = [];
        $requestedBy = [];
        $requests = 0;
        $late = [];

        foreach ($this->trace->getRenders() as $render) {
            $name = $render['name'];
            $metadata = $render['metadata'];

            $components[$name] ??= [
                'name' => $name,
                'class' => $render['class'],
                'template' => $render['template'],
                'render_count' => 0,
                'assets' => array_map(
                    static fn (array $asset): array => [
                        'path' => $asset['path'],
                        'type' => $asset['type'],
                        'source' => $asset['source'],
                        'priority' => $asset['priority'],
                    ],
                    $metadata->assets,
                ),
                'problems' => $metadata->getProblemMessages(),
            ];
            ++$components[$name]['render_count'];

            foreach ($metadata->assets as $asset) {
                ++$requests;
                $requestedBy[$asset['path']][$name] = $name;
            }

            if ($render['afterResponse'] && [] !== $metadata->assets) {
                $late[$name] = $name;
            }
        }

        foreach ($this->data['assets'] as $index => $asset) {
            $this->data['assets'][$index]['requested_by'] = array_values($requestedBy[$asset['path']] ?? []);
        }

        $this->data['components'] = array_values($components);
        $this->data['asset_requests'] = $requests;
        $this->data['late_components'] = array_values($late);
    }

    /**
     * @return list<SdcCollectedProblem>
     */
    private function problems(): array
    {
        $problems = [];

        if (RequestTrace::OUTCOME_MISSING_PLACEHOLDER === $this->data['outcome'] && [] !== $this->data['assets']) {
            $problems[] = self::problem('The page has no placeholder for the component assets, so none of them is loaded: they only went into the X-SDC-Assets header, which nothing reads on a page load. Call {{ render_component_assets() }} inside the <head> of the base layout.');
        }

        if ($this->data['import_map_after_modules']) {
            $problems[] = self::problem('The page injects component scripts, which are ES modules, before its import map: browsers that do not merge import maps ignore it, and the bare imports of the page fail to resolve. Call {{ render_component_assets() }} after {{ importmap() }}.');
        }

        foreach ($this->data['assets'] as $asset) {
            if (null !== $asset['public_path'] && $asset['public_path'] === $asset['path'] && !AssetPath::isUrl($asset['path'])) {
                $problems[] = self::problem(\sprintf('The asset "%s" is not in the asset map, so it is linked as it is and will most likely not load. Reference it by its AssetMapper logical path (see "bin/console debug:asset-map"), or by a URL.', $asset['path']));
            }
        }

        foreach ($this->data['dropped'] as $asset) {
            $problems[] = self::problem(\sprintf('The asset "%s" is not loaded: %s', $asset['path'], $asset['reason']));
        }

        if (null !== $this->data['header_size'] && $this->data['header_size'] > FragmentAssetHeader::SIZE_WARNING) {
            $problems[] = self::problem(\sprintf('The X-SDC-Assets header is %d bytes long. A reverse proxy fails a response whose headers do not fit its buffer — nginx in front of PHP-FPM answers 502 "upstream sent too big header" once they exceed fastcgi_buffer_size (4k or 8k by default).', $this->data['header_size']));
        }

        foreach ($this->data['components'] as $component) {
            foreach ($component['problems'] as $message) {
                $problems[] = self::problem($message, $component['name']);
            }
        }

        foreach ($this->data['late_components'] as $name) {
            $problems[] = self::problem('The component rendered after the response had got its assets — in the body of a streamed response — so its assets are not in the page.', $name);
        }

        return $problems;
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return SdcCollectedAsset
     */
    private function asset(string $path, ?string $publicPath, string $type, array $attributes, ?string $emittedAs, bool $linkHeader): array
    {
        return [
            'path' => $path,
            'public_path' => $publicPath,
            'type' => $type,
            'attributes' => $attributes,
            'emitted_as' => $emittedAs,
            'link_header' => $linkHeader,
            'requested_by' => [],
        ];
    }

    /**
     * How PageAssetInjector or FragmentAssetHeader emitted the asset.
     *
     * @param bool $injected whether the response is a page that got the tags, rather than the X-SDC-Assets header
     *
     * @return SdcCollectedAsset
     */
    private function resolvedAsset(ResolvedAsset $asset, bool $injected): array
    {
        if ($injected) {
            [$emittedAs, $linkHeader] = match (true) {
                $asset->isStylesheet() => ['<link rel="stylesheet">', true],
                $asset->isModule() => ['<script type="module">', false],
                $asset->isScript() => ['<script>', true],
                default => ['<link rel="preload">', true],
            };
        } else {
            // A fragment carries no preloads: the stylesheet or the markup that
            // uses the file arrives with it, which is when the browser fetches it anyway.
            $emittedAs = match (true) {
                $asset->isStylesheet() => FragmentAssetHeader::NAME . ': css',
                $asset->isModule() => FragmentAssetHeader::NAME . ': module',
                $asset->isScript() => FragmentAssetHeader::NAME . ': script',
                default => null,
            };
            $linkHeader = false;
        }

        return $this->asset($asset->declaredPath, $asset->path, $asset->type, $asset->attributes, $emittedAs, $linkHeader);
    }

    /**
     * The assets of the registry AssetResolver left out: those of a type the
     * bundle does not load, which only AssetRegistry::addAsset() called by
     * hand can register.
     *
     * @param list<SdcAsset>      $registered
     * @param list<ResolvedAsset> $resolved
     *
     * @return list<array{path: string, type: string, reason: string}>
     */
    private function droppedAssets(array $registered, array $resolved): array
    {
        $kept = [];
        foreach ($resolved as $asset) {
            $kept[$asset->declaredPath] = true;
        }

        $dropped = [];
        foreach ($registered as $asset) {
            if (!isset($kept[$asset['path']])) {
                $dropped[] = [
                    'path' => $asset['path'],
                    'type' => $asset['type'],
                    'reason' => '' === $asset['type']
                        ? 'it was registered without a type, and its extension tells none.'
                        : \sprintf('its type "%s" is none of those the bundle loads (%s).', $asset['type'], implode(', ', AssetType::ALL)),
                ];
            }
        }

        return $dropped;
    }

    /**
     * @return SdcCollectedProblem
     */
    private static function problem(string $message, ?string $component = null): array
    {
        return ['message' => $message, 'component' => $component];
    }
}
