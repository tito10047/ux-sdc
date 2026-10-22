<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Unit\Response;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tito10047\UX\Sdc\Response\ResponseClassifier;

final class ResponseClassifierTest extends TestCase
{
    /**
     * @return iterable<string, array{Request, Response, bool}>
     */
    public static function provideHtmlResponses(): iterable
    {
        yield 'no Content-Type, HTML request format' => [new Request(), new Response(), true];
        yield 'HTML' => [new Request(), new Response('', 200, ['Content-Type' => 'text/html; charset=UTF-8']), true];
        yield 'XHTML' => [new Request(), new Response('', 200, ['Content-Type' => 'application/xhtml+xml']), true];
        yield 'Live Component' => [new Request(), new Response('', 200, ['Content-Type' => 'application/vnd.live-component+html']), true];
        yield 'Turbo Stream' => [new Request(), new Response('', 200, ['Content-Type' => 'text/vnd.turbo-stream.html']), true];
        yield 'JSON' => [new Request(), new Response('', 200, ['Content-Type' => 'application/json']), false];

        $request = new Request();
        $request->setRequestFormat('json');
        yield 'no Content-Type, JSON request format' => [$request, new Response(), false];
    }

    #[DataProvider('provideHtmlResponses')]
    public function testTellsAnHtmlResponse(Request $request, Response $response, bool $html): void
    {
        $this->assertSame($html, (new ResponseClassifier())->isHtml($request, $response));
    }

    /**
     * @return iterable<string, array{Request, Response, bool}>
     */
    public static function provideFragmentResponses(): iterable
    {
        yield 'page' => [new Request(), new Response(), false];

        $request = new Request();
        $request->headers->set('Accept', 'text/vnd.turbo-stream.html, text/html');
        yield 'Turbo form submission answered with a page' => [$request, new Response(), false];

        $request = new Request();
        $request->headers->set('Accept', 'application/vnd.live-component+html, */*;q=0.8');
        yield 'Live Component request' => [$request, new Response(), true];

        yield 'Live Component response' => [new Request(), new Response('', 200, ['Content-Type' => 'application/vnd.live-component+html']), true];

        $request = new Request();
        $request->headers->set('Turbo-Frame', 'cart');
        yield 'Turbo Frame request' => [$request, new Response(), true];

        yield 'Turbo Stream response' => [new Request(), new Response('', 200, ['Content-Type' => 'Text/Vnd.Turbo-Stream.Html; charset=UTF-8']), true];

        $request = new Request();
        $request->setFormat('turbo_stream', 'text/vnd.turbo-stream.html');
        $request->setRequestFormat('turbo_stream');
        yield 'Turbo Stream request format' => [$request, new Response(), true];
    }

    #[DataProvider('provideFragmentResponses')]
    public function testTellsAFragmentResponse(Request $request, Response $response, bool $fragment): void
    {
        $this->assertSame($fragment, (new ResponseClassifier())->isFragment($request, $response));
    }
}
