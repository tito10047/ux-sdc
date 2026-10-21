<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Records what is logged, for assertions. psr/log no longer ships a test
 * logger (3.x), and Symfony's BufferingLogger dumps whatever is left in it
 * to the error log on destruction.
 */
final class TestLogger extends AbstractLogger
{
    /**
     * @var list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public array $records = [];

    /**
     * @param string|\Stringable   $message
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function recordsOf(string $level): array
    {
        return array_values(array_filter($this->records, static fn (array $record): bool => $level === $record['level']));
    }

    /**
     * The records of level warning and above: what a production log keeps.
     *
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function problems(): array
    {
        $levels = [LogLevel::WARNING, LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY];

        return array_values(array_filter($this->records, static fn (array $record): bool => \in_array($record['level'], $levels, true)));
    }
}
