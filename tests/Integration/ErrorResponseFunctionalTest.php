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
use Tito10047\UX\Sdc\Tests\TestLogger;

/**
 * A component that fails while the page renders: the error page Symfony
 * answers with gets the assets of its own components, never those of the page
 * that failed.
 *
 * failing.html.twig renders AutoDiscovery:AutoDiscoveryComponent, then
 * Failing:Broken, which throws from its template after its assets were
 * registered. Without debug, TwigErrorRenderer renders the application's
 * error.html.twig (Fixtures/error_pages, mounted as the `@Twig` override),
 * or Symfony's static error page when there is none; with debug, the
 * exception page.
 */
final class ErrorResponseFunctionalTest extends FunctionalTestCase
{
    private const FAILED_PAGE_ASSETS = ['AutoDiscovery/AutoDiscoveryComponent', 'Failing/Broken'];

    public function testAnErrorPageWithComponentsGetsExactlyTheirAssets(): void
    {
        $client = self::createErrorClient(debug: false, errorTemplate: true);

        $client->request('GET', '/page/failing.html.twig');

        self::assertResponseStatusCodeSame(500);
        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Something went wrong.', $content);
        $this->assertStringContainsString('Anonymous/Notice/Notice', $content);
        $this->assertStringContainsString('Anonymous/Icon/Icon', $content);
        foreach (self::FAILED_PAGE_ASSETS as $asset) {
            $this->assertStringNotContainsString($asset, $content, 'The page that failed is not the one the client gets.');
        }
        $this->assertStringNotContainsString('__UX_TWIG_COMPONENT_ASSETS__', $content);
        self::assertResponseNotHasHeader('X-SDC-Assets');
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function provideErrorPagesWithoutComponents(): iterable
    {
        yield 'Symfony\'s error page' => [false];
        yield 'exception page (debug)' => [true];
    }

    /**
     * Symfony's error page and its exception page are documents without the
     * placeholder. They used to get the assets of the page that failed in the
     * X-SDC-Assets header, and in debug a warning about the missing placeholder.
     */
    #[DataProvider('provideErrorPagesWithoutComponents')]
    public function testAnErrorPageWithoutComponentsGetsNoAssets(bool $debug): void
    {
        $client = self::createErrorClient(debug: $debug, errorTemplate: false);

        $client->request('GET', '/page/failing.html.twig');

        self::assertResponseStatusCodeSame(500);
        self::assertResponseNotHasHeader('X-SDC-Assets');
        if ($debug) {
            $this->assertSame([], self::placeholderWarnings($client));
        }
    }

    /**
     * What was observed in production: a 500 with an empty body and an
     * X-SDC-Assets header listing the assets of the component that failed. A
     * HEAD request gets the headers of the GET one, without the body.
     */
    public function testAHeadRequestOfAFailingPageGetsNoAssets(): void
    {
        $client = self::createErrorClient(debug: false, errorTemplate: false);

        $client->request('HEAD', '/page/failing.html.twig');

        self::assertResponseStatusCodeSame(500);
        $this->assertSame('', $client->getResponse()->getContent());
        self::assertResponseNotHasHeader('X-SDC-Assets');
    }

    /**
     * The registry is cleared for the error page only: the next request in the
     * same kernel collects its assets as usual.
     */
    public function testTheNextPageGetsItsAssets(): void
    {
        $client = self::createErrorClient(debug: false, errorTemplate: true);
        $client->disableReboot();

        $client->request('GET', '/page/failing.html.twig');
        self::assertResponseStatusCodeSame(500);

        $client->request('GET', '/page/auto_discovery.html.twig');
        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('AutoDiscovery/AutoDiscoveryComponent', $content);
        $this->assertStringNotContainsString('Anonymous/Notice/Notice', $content);
    }

    /**
     * A Turbo Frame request of a failing page: its error response is a
     * fragment, and lists the assets of the error page's own components.
     */
    public function testAFragmentErrorResponseListsOnlyTheAssetsOfTheErrorPage(): void
    {
        $client = self::createErrorClient(debug: false, errorTemplate: true);

        $client->request('GET', '/page/failing.html.twig', server: ['HTTP_TURBO_FRAME' => 'content']);

        self::assertResponseStatusCodeSame(500);
        $header = (string) $client->getResponse()->headers->get('X-SDC-Assets');
        $this->assertStringContainsString('Anonymous/Notice/Notice', $header);
        foreach (self::FAILED_PAGE_ASSETS as $asset) {
            $this->assertStringNotContainsString($asset, $header);
        }
    }

    private static function createErrorClient(bool $debug, bool $errorTemplate): KernelBrowser
    {
        return self::createClient([
            'debug' => $debug,
            'twig' => $errorTemplate ? ['paths' => ['%kernel.project_dir%/tests/Integration/Fixtures/error_pages' => 'Twig']] : [],
            'compiler_passes' => [new RegisterTestLoggerPass()],
        ]);
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    private static function placeholderWarnings(KernelBrowser $client): array
    {
        $logger = $client->getContainer()->get('logger');
        self::assertInstanceOf(TestLogger::class, $logger);

        return array_values(array_filter(
            $logger->recordsOf('warning'),
            static fn (array $record): bool => str_contains($record['message'], 'placeholder'),
        ));
    }
}
