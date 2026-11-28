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

use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Service\ResetInterface;
use Tito10047\UX\Sdc\Response\ResolvedAsset;
use Tito10047\UX\Sdc\Service\AssetRegistry;
use Tito10047\UX\Sdc\Service\ComponentMetadata;

/**
 * What the bundle did during the current request, as the services doing it
 * record it, for SdcDataCollector: the components that rendered, with the
 * metadata their assets came from, and how each response got those assets.
 *
 * It exists only in a debug container with the profiler. The render listeners,
 * AssetResponseListener and PageAssetInjector take it as an optional argument,
 * which is null anywhere else, so outside the profiler they record nothing.
 *
 * `SdcRender` is one render of one component; `afterResponse` tells that it
 * happened after the main response had got its assets — in the body of a
 * streamed response, say — so they are not in it.
 *
 * `SdcHandledResponse` is what AssetResponseListener did with a response: one
 * of the OUTCOME_* constants, the assets the registry held at that point, and
 * the ones the response links to.
 *
 * When the request fails, the assets the registry held are recorded as
 * discarded: the error response replacing the page does not get them.
 *
 * @phpstan-import-type SdcAsset from AssetRegistry
 *
 * @phpstan-type SdcRender array{name: string, class: string|null, template: string, metadata: ComponentMetadata, afterResponse: bool}
 * @phpstan-type SdcHandledResponse array{outcome: self::OUTCOME_*, registered: list<SdcAsset>, resolved: list<ResolvedAsset>}
 *
 * @internal
 */
final class RequestTrace implements ResetInterface
{
    /** A page: the tags replaced its placeholder. */
    public const OUTCOME_INJECTED = 'injected';

    /** A Live Component re-render, a Turbo Frame or Turbo Streams: the X-SDC-Assets header. */
    public const OUTCOME_FRAGMENT = 'fragment';

    /** HTML without the placeholder and without a <head>, a partial a script inserts: the X-SDC-Assets header. */
    public const OUTCOME_PARTIAL = 'partial';

    /** A page without the placeholder: only the X-SDC-Assets header, which a page load does not read. */
    public const OUTCOME_MISSING_PLACEHOLDER = 'missing_placeholder';

    /** Not HTML (JSON, a file): nothing. */
    public const OUTCOME_NOT_HTML = 'not_html';

    /** A streamed or binary response, whose content cannot be changed: nothing. */
    public const OUTCOME_STREAMED = 'streamed';

    /** A redirect, whose body the client never shows: nothing. */
    public const OUTCOME_REDIRECT = 'redirect';

    /** A response without a body to show (204, 304, empty content): nothing. */
    public const OUTCOME_EMPTY = 'empty';

    /** A sub-request: nothing, its components' assets go into the main response. */
    public const OUTCOME_SUB_REQUEST = 'sub_request';

    /**
     * @var list<SdcRender>
     */
    private array $renders = [];

    /**
     * @var \WeakMap<Request, SdcHandledResponse>
     */
    private \WeakMap $responses;

    /**
     * @var \WeakMap<Request, true>
     */
    private \WeakMap $importMapsAfterModules;

    /**
     * @var \WeakMap<Request, list<SdcAsset>>
     */
    private \WeakMap $discarded;

    private bool $mainResponseHandled = false;

    public function __construct()
    {
        $this->responses = new \WeakMap();
        $this->importMapsAfterModules = new \WeakMap();
        $this->discarded = new \WeakMap();
    }

    /**
     * @param string|null $class    null for an anonymous component
     * @param string      $template the template the component renders with
     */
    public function componentRendered(string $name, ?string $class, string $template, ComponentMetadata $metadata): void
    {
        $this->renders[] = [
            'name' => $name,
            'class' => $class,
            'template' => $template,
            'metadata' => $metadata,
            'afterResponse' => $this->mainResponseHandled,
        ];
    }

    /**
     * @param self::OUTCOME_*     $outcome
     * @param list<SdcAsset>      $registered the assets of the registry
     * @param list<ResolvedAsset> $resolved   the assets the response links to
     */
    public function responseHandled(Request $request, string $outcome, array $registered = [], array $resolved = []): void
    {
        $this->responses[$request] = ['outcome' => $outcome, 'registered' => $registered, 'resolved' => $resolved];

        if (self::OUTCOME_SUB_REQUEST !== $outcome) {
            $this->mainResponseHandled = true;
        }
    }

    /**
     * The main request failed: the assets the registry held are dropped, and
     * the error response replacing the page starts over. Its components render
     * for that response, even when the page had already got its assets —
     * a `kernel.response` listener failed after AssetResponseListener.
     *
     * @param list<SdcAsset> $discarded
     */
    public function requestFailed(Request $request, array $discarded): void
    {
        $this->discarded[$request] = [...($this->discarded[$request] ?? []), ...$discarded];
        $this->mainResponseHandled = false;
    }

    /**
     * The page injects module scripts before its import map.
     */
    public function importMapAfterModules(Request $request): void
    {
        $this->importMapsAfterModules[$request] = true;
    }

    /**
     * @return list<SdcRender>
     */
    public function getRenders(): array
    {
        return $this->renders;
    }

    /**
     * @return SdcHandledResponse|null null when AssetResponseListener did not see the response
     */
    public function getHandledResponse(Request $request): ?array
    {
        return $this->responses[$request] ?? null;
    }

    /**
     * @return list<SdcAsset> the assets the failure of the request dropped
     */
    public function getDiscardedAssets(Request $request): array
    {
        return $this->discarded[$request] ?? [];
    }

    public function hasImportMapAfterModules(Request $request): bool
    {
        return isset($this->importMapsAfterModules[$request]);
    }

    /**
     * Called by the `services_resetter` between requests, which a long-running
     * worker serves with the same container.
     */
    public function reset(): void
    {
        $this->renders = [];
        $this->responses = new \WeakMap();
        $this->importMapsAfterModules = new \WeakMap();
        $this->discarded = new \WeakMap();
        $this->mainResponseHandled = false;
    }
}
