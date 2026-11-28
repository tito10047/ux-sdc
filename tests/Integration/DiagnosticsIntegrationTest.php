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
use Psr\Log\LogLevel;
use Tito10047\UX\Sdc\Service\ComponentMetadataResolver;
use Tito10047\UX\Sdc\Tests\Integration\Fixtures\Component\AutoDiscovery\AutoDiscoveryComponent;
use Tito10047\UX\Sdc\Tests\TestLogger;
use Tito10047\UX\Sdc\Tests\Unit\Service\Fixtures\Alert\Alert;

/**
 * The three ways component assets can go missing without anything failing are
 * logged, through the application's `logger` service, when — and only when —
 * there is something to act on.
 */
final class DiagnosticsIntegrationTest extends FunctionalTestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideKernelsWithTheCache(): iterable
    {
        yield 'prod without debug' => ['prod', false];
        yield 'dev with debug' => ['dev', true];
    }

    /**
     * A deployment error: the file is written by every build with the cache on,
     * so its absence is logged as an error, in production too. The page is
     * still delivered.
     */
    #[DataProvider('provideKernelsWithTheCache')]
    public function testMissingMetadataCacheIsLoggedAsAnError(string $environment, bool $debug): void
    {
        $client = $this->createClientWithLogger($environment, $debug, ['metadata_cache' => true]);
        $cachePath = self::getContainer()->getParameter('kernel.cache_dir') . '/sdc_metadata.php';
        $this->assertFileExists($cachePath);
        unlink($cachePath);

        $client->request('GET', '/page/auto_discovery.html.twig');

        self::assertResponseIsSuccessful();
        $this->assertStringNotContainsString('AutoDiscoveryComponent', $this->headOf((string) $client->getResponse()->getContent()));

        $errors = $this->logger()->problems();
        $this->assertCount(1, $errors);
        $this->assertSame(LogLevel::ERROR, $errors[0]['level']);
        $this->assertSame(['path' => $cachePath], $errors[0]['context']);
    }

    public function testPresentMetadataCacheLogsNothing(): void
    {
        $client = $this->createClientWithLogger('prod', false, ['metadata_cache' => true]);

        $client->request('GET', '/page/auto_discovery.html.twig');

        self::assertResponseIsSuccessful();
        $this->assertSame([], $this->logger()->problems());
    }

    public function testMissingPlaceholderIsReportedInDebug(): void
    {
        $client = $this->createClientWithLogger('dev', true);

        $client->request('GET', '/page/no_placeholder.html.twig');

        self::assertResponseIsSuccessful();
        $warnings = $this->logger()->problems();
        $this->assertCount(1, $warnings);
        $this->assertSame('/page/no_placeholder.html.twig', $warnings[0]['context']['uri']);
        $this->assertSame('AutoDiscovery/AutoDiscoveryComponent.css, AutoDiscovery/AutoDiscoveryComponent.js', $warnings[0]['context']['assets']);
    }

    /**
     * @return iterable<string, array{string, bool, string}>
     */
    public static function providePagesThatAreNotReported(): iterable
    {
        yield 'missing placeholder, not in debug' => ['prod', false, 'no_placeholder.html.twig'];
        yield 'fragment in debug' => ['dev', true, 'fragment.html.twig'];
        yield 'layout with the placeholder in debug' => ['dev', true, 'auto_discovery.html.twig'];
    }

    #[DataProvider('providePagesThatAreNotReported')]
    public function testPageIsNotReported(string $environment, bool $debug, string $template): void
    {
        $client = $this->createClientWithLogger($environment, $debug);

        $client->request('GET', '/page/' . $template);

        self::assertResponseIsSuccessful();
        $this->assertSame([], $this->logger()->problems());
    }

    /**
     * The runtime resolver, as wired in the container: with the Twig paths of
     * the application, the logger and kernel.debug.
     */
    public function testRuntimeResolverReportsFilesOutsideEveryTwigPathInDebug(): void
    {
        $this->createClientWithLogger('dev', true, ['metadata_cache' => false]);
        $resolver = self::getContainer()->get(ComponentMetadataResolver::class);
        $this->assertInstanceOf(ComponentMetadataResolver::class, $resolver);

        $this->assertSame('AutoDiscovery/AutoDiscoveryComponent.html.twig', $resolver->resolve(AutoDiscoveryComponent::class)->template);
        $this->assertSame([], $this->logger()->problems());

        $this->assertNull($resolver->resolve(Alert::class)->template);
        $warnings = $this->logger()->problems();
        $this->assertCount(3, $warnings);
        $this->assertSame(Alert::class, $warnings[0]['context']['component']);
    }

    public function testRuntimeResolverIsQuietWithoutDebug(): void
    {
        $this->createClientWithLogger('prod', false, ['metadata_cache' => false]);
        $resolver = self::getContainer()->get(ComponentMetadataResolver::class);
        $this->assertInstanceOf(ComponentMetadataResolver::class, $resolver);

        $this->assertNull($resolver->resolve(Alert::class)->template);
        $this->assertSame([], $this->logger()->problems());
    }

    /**
     * @param array<string, mixed> $configs
     */
    private function createClientWithLogger(string $environment, bool $debug, array $configs = []): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        return self::createClient([
            'environment' => $environment,
            'debug' => $debug,
            'configs' => $configs,
            'compiler_passes' => [new RegisterTestLoggerPass()],
        ]);
    }

    private function logger(): TestLogger
    {
        $logger = self::getContainer()->get('logger');
        $this->assertInstanceOf(TestLogger::class, $logger);

        return $logger;
    }

    private function headOf(string $html): string
    {
        return strstr($html, '</head>', true) ?: $html;
    }
}
