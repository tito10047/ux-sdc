<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Response;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tells what kind of response the assets of its components go into: HTML at
 * all, and if so, a page the browser loads or a fragment the client takes
 * apart.
 *
 * It runs on `kernel.response`, before `Response::prepare()` has given the
 * response its final Content-Type, so a response without one is judged by the
 * one it is going to get.
 *
 * @internal
 */
final class ResponseClassifier
{
    private const LIVE_COMPONENT_MEDIA_TYPE = 'application/vnd.live-component+html';
    private const TURBO_STREAM_MEDIA_TYPE = 'text/vnd.turbo-stream.html';

    /**
     * Mirrors the check of the web profiler toolbar, which injects into the same
     * responses: the Content-Type mentions HTML. Live Component
     * (`application/vnd.live-component+html`) and Turbo Stream
     * (`text/vnd.turbo-stream.html`) responses are HTML too.
     */
    public function isHtml(Request $request, Response $response): bool
    {
        return str_contains($this->mediaType($request, $response) ?? '', 'html');
    }

    /**
     * A response the client takes apart instead of loading it as a page:
     *
     *  - a Live Component re-render: the request accepts
     *    `application/vnd.live-component+html`, or the response is of that type;
     *  - a Turbo Frame navigation or form submission: the request has the
     *    `Turbo-Frame` header, which Turbo sends only for those;
     *  - Turbo Streams: the response is `text/vnd.turbo-stream.html`. Not the
     *    `Accept` header — Turbo accepts streams with every form submission,
     *    and the response may as well be a whole page.
     */
    public function isFragment(Request $request, Response $response): bool
    {
        $mediaType = strtolower(trim(explode(';', $this->mediaType($request, $response) ?? '')[0]));

        return self::LIVE_COMPONENT_MEDIA_TYPE === $mediaType
            || self::TURBO_STREAM_MEDIA_TYPE === $mediaType
            || $request->headers->has('Turbo-Frame')
            || \in_array(self::LIVE_COMPONENT_MEDIA_TYPE, $request->getAcceptableContentTypes(), true);
    }

    /**
     * The Content-Type of the response, or, when the controller did not set one,
     * the one `Response::prepare()` will derive from the request format — after
     * the listener has run. A controller answering Turbo Streams typically sets
     * only the format (`$request->setRequestFormat(TurboBundle::STREAM_FORMAT)`).
     */
    private function mediaType(Request $request, Response $response): ?string
    {
        return $response->headers->get('Content-Type') ?? $request->getMimeType($request->getRequestFormat() ?? 'html');
    }
}
