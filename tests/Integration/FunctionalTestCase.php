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

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Sends real requests through the TestKernel with the KernelBrowser.
 *
 * TestKernel routes `/page/{template}` to any template of Fixtures/templates,
 * so a test of the whole request lifecycle only needs a template.
 */
abstract class FunctionalTestCase extends WebTestCase
{
    use CreatesTestKernel;
}
