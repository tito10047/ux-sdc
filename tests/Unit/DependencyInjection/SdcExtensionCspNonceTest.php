<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Unit\DependencyInjection;

use Nelmio\SecurityBundle\NelmioSecurityBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Tito10047\UX\Sdc\Csp\NelmioNonceProvider;
use Tito10047\UX\Sdc\DependencyInjection\SdcExtension;
use Tito10047\UX\Sdc\EventListener\AssetResponseListener;

final class SdcExtensionCspNonceTest extends TestCase
{
    public function testTagsGetNoNonceByDefault(): void
    {
        $container = $this->load([]);

        $this->assertArrayNotHasKey('$nonceProvider', $container->getDefinition(AssetResponseListener::class)->getArguments());
    }

    public function testTheListenerGetsTheSelectedProvider(): void
    {
        $container = $this->load(['csp_nonce_provider' => 'app.csp_nonces']);

        $argument = $container->getDefinition(AssetResponseListener::class)->getArgument('$nonceProvider');
        $this->assertInstanceOf(Reference::class, $argument);
        $this->assertSame('app.csp_nonces', (string) $argument);
    }

    public function testTheNelmioAdapterNeedsNelmioSecurityBundle(): void
    {
        $this->assertFalse($this->load([])->hasDefinition(NelmioNonceProvider::class));
        $this->assertTrue($this->load([], [NelmioSecurityBundle::class])->hasDefinition(NelmioNonceProvider::class));
    }

    /**
     * NelmioSecurityBundle is installed for other features, its CSP listener is
     * not: the unused adapter goes away instead of failing the container on the
     * listener it refers to.
     */
    public function testAnUnusedNelmioAdapterDoesNotNeedNelmioCsp(): void
    {
        $container = $this->load([], [NelmioSecurityBundle::class]);
        $this->assertFalse($container->hasDefinition('nelmio_security.csp_listener'));

        // Keep the rest of the bundle out of the way: only the adapter is under test.
        foreach (array_keys($container->getDefinitions()) as $id) {
            if (NelmioNonceProvider::class !== $id && 'service_container' !== $id) {
                $container->removeDefinition($id);
            }
        }

        $container->compile();

        $this->assertFalse($container->hasDefinition(NelmioNonceProvider::class));
    }

    /**
     * @param array<string, mixed> $config
     * @param list<class-string>   $bundles
     */
    private function load(array $config, array $bundles = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', realpath(__DIR__ . '/../../..'));
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', true);
        $container->setParameter('kernel.bundles', array_combine(
            array_map(static fn (string $class): string => substr($class, strrpos($class, '\\') + 1), $bundles),
            $bundles,
        ));

        (new SdcExtension())->load([$config], $container);

        return $container;
    }
}
