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

use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Builds the TestKernel for KernelTestCase::bootKernel() and WebTestCase::createClient().
 *
 * Options on top of `environment` and `debug`:
 *  - `configs`: the `ux_sdc` configuration
 *  - `compiler_passes`: extra compiler passes, see TestKernel
 */
trait CreatesTestKernel
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $env = $options['environment'] ?? $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'test';
        $debug = $options['debug'] ?? $_ENV['APP_DEBUG'] ?? $_SERVER['APP_DEBUG'] ?? true;

        return new TestKernel(
            $options['configs'] ?? [],
            $env,
            (bool) $debug,
            compilerPasses: $options['compiler_passes'] ?? [],
        );
    }
}
