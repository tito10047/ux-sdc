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
use Tito10047\UX\Sdc\DataCollector\RequestTrace;
use Tito10047\UX\Sdc\Service\ComponentMetadata;

final class RequestTraceTest extends TestCase
{
    public function testTellsTheRendersThatCameAfterTheMainResponse(): void
    {
        $trace = new RequestTrace();
        $main = new Request();

        $trace->componentRendered('Page', 'App\Page', 'Page.html.twig', new ComponentMetadata());
        $trace->responseHandled(new Request(), RequestTrace::OUTCOME_SUB_REQUEST);
        $trace->componentRendered('Embedded', 'App\Embedded', 'Embedded.html.twig', new ComponentMetadata());
        $trace->responseHandled($main, RequestTrace::OUTCOME_STREAMED);
        $trace->componentRendered('Streamed', 'App\Streamed', 'Streamed.html.twig', new ComponentMetadata());

        $this->assertSame(
            ['Page' => false, 'Embedded' => false, 'Streamed' => true],
            array_column($trace->getRenders(), 'afterResponse', 'name'),
        );
        $this->assertSame(RequestTrace::OUTCOME_STREAMED, $trace->getHandledResponse($main)['outcome'] ?? null);
    }

    public function testKeepsTheResponsesApart(): void
    {
        $trace = new RequestTrace();
        $page = new Request();
        $fragment = new Request();

        $trace->importMapAfterModules($page);
        $trace->responseHandled($page, RequestTrace::OUTCOME_INJECTED);

        $this->assertTrue($trace->hasImportMapAfterModules($page));
        $this->assertFalse($trace->hasImportMapAfterModules($fragment));
        $this->assertNull($trace->getHandledResponse($fragment));
    }

    /**
     * The error response replaces the page: its components render for it,
     * even when the page had already got its assets.
     */
    public function testAFailedRequestStartsOver(): void
    {
        $trace = new RequestTrace();
        $request = new Request();
        $other = new Request();
        $trace->responseHandled($request, RequestTrace::OUTCOME_INJECTED);

        $trace->requestFailed($request, [['path' => 'Broken.css', 'type' => 'css', 'priority' => 0, 'attributes' => []]]);
        $trace->componentRendered('Notice', null, 'Notice.html.twig', new ComponentMetadata());

        $this->assertFalse($trace->getRenders()[0]['afterResponse']);
        $this->assertSame([['path' => 'Broken.css', 'type' => 'css', 'priority' => 0, 'attributes' => []]], $trace->getDiscardedAssets($request));
        $this->assertSame([], $trace->getDiscardedAssets($other));
    }

    public function testResetForgetsTheRequest(): void
    {
        $trace = new RequestTrace();
        $request = new Request();
        $trace->componentRendered('Alert', 'App\Alert', 'Alert.html.twig', new ComponentMetadata());
        $trace->importMapAfterModules($request);
        $trace->responseHandled($request, RequestTrace::OUTCOME_INJECTED);
        $trace->requestFailed($request, [['path' => 'Broken.css', 'type' => 'css', 'priority' => 0, 'attributes' => []]]);

        $trace->reset();
        $this->assertSame([], $trace->getDiscardedAssets($request));

        $this->assertSame([], $trace->getRenders());
        $this->assertNull($trace->getHandledResponse($request));
        $this->assertFalse($trace->hasImportMapAfterModules($request));

        $trace->componentRendered('Alert', 'App\Alert', 'Alert.html.twig', new ComponentMetadata());
        $this->assertFalse($trace->getRenders()[0]['afterResponse'], 'The next request starts before its response.');
    }
}
