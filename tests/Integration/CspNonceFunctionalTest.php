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

use Nelmio\SecurityBundle\NelmioSecurityBundle;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Tito10047\UX\Sdc\Csp\NelmioNonceProvider;

/**
 * The nonce of `ux_sdc.csp_nonce_provider` on the injected tags, with a
 * provider of the application and with NelmioSecurityBundle.
 */
final class CspNonceFunctionalTest extends FunctionalTestCase
{
    private const PAGE = '/page/esm.html.twig';

    private const NELMIO_CSP = ['csp' => ['enforce' => [
        'script-src' => ["'self'"],
        'style-src' => ["'self'"],
    ]]];

    /**
     * @return iterable<string, array{bool}>
     */
    public static function provideMetadataCache(): iterable
    {
        yield 'metadata resolved on render' => [false];
        yield 'metadata from the build-time cache' => [true];
    }

    #[DataProvider('provideMetadataCache')]
    public function testTagsGetTheNoncesOfTheApplicationProvider(bool $metadataCache): void
    {
        $client = self::createClient([
            'configs' => ['metadata_cache' => $metadataCache, 'csp_nonce_provider' => FixedNonceProvider::class],
            'services' => [FixedNonceProvider::class],
        ]);
        $client->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        $this->assertSame(2, substr_count($html, \sprintf(' nonce="%s"></script>', FixedNonceProvider::SCRIPT_NONCE)), 'The module and the classic script.');
        $this->assertMatchesRegularExpression(\sprintf('~<script src="[^"]+" type="module" nonce="%s"></script>~', FixedNonceProvider::SCRIPT_NONCE), $html);
        $this->assertMatchesRegularExpression(\sprintf('~<link rel="stylesheet" href="[^"]+" nonce="%s">~', preg_quote(FixedNonceProvider::STYLE_NONCE, '~')), $html);
    }

    public function testTagsGetTheNonceNelmioPutsInThePolicy(): void
    {
        $client = $this->createNelmioClient(['csp_nonce_provider' => NelmioNonceProvider::class]);

        $first = $this->requestNonces($client);
        $this->assertNotSame('', $first);

        // The same container serves the next request, the way a long-running
        // worker does: it gets a nonce of its own.
        $second = $this->requestNonces($client);
        $this->assertNotSame($first, $second);
    }

    /**
     * Opt-in: once a nonce is in a policy, browsers ignore its 'unsafe-inline',
     * so asking Nelmio for one behind the application's back could block the
     * application's own inline scripts.
     */
    public function testNelmioNoncesAreNotUsedUnlessSelected(): void
    {
        $client = $this->createNelmioClient([]);
        $client->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        $this->assertStringNotContainsString('nonce', (string) $client->getResponse()->getContent());
        $this->assertStringNotContainsString('nonce', (string) $client->getResponse()->headers->get('Content-Security-Policy'));
    }

    /**
     * @param array<string, mixed> $configs
     */
    private function createNelmioClient(array $configs): KernelBrowser
    {
        $client = self::createClient([
            'configs' => $configs,
            'bundles' => [new NelmioSecurityBundle()],
            'extensions' => ['nelmio_security' => self::NELMIO_CSP],
        ]);
        $client->disableReboot();

        return $client;
    }

    /**
     * Requests the page, checks that every tag carries the nonce the policy
     * allows for its kind, and returns it.
     */
    private function requestNonces(KernelBrowser $client): string
    {
        $client->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        $policy = (string) $client->getResponse()->headers->get('Content-Security-Policy');

        $this->assertSame(1, preg_match('~<script src="[^"]+" type="module" nonce="([^"]+)"></script>~', $html, $match), $html);
        $nonce = $match[1];

        $this->assertMatchesRegularExpression(\sprintf('~<script src="[^"]+" nonce="%s"></script>~', preg_quote($nonce, '~')), $html, 'The classic script.');
        $this->assertMatchesRegularExpression(\sprintf('~<link rel="stylesheet" href="[^"]+" nonce="%s">~', preg_quote($nonce, '~')), $html);
        $this->assertMatchesRegularExpression(\sprintf("~script-src [^;]*'nonce-%s'~", preg_quote($nonce, '~')), $policy);
        $this->assertMatchesRegularExpression(\sprintf("~style-src [^;]*'nonce-%s'~", preg_quote($nonce, '~')), $policy);

        return $nonce;
    }
}
