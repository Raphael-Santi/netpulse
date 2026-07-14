<?php

declare(strict_types=1);

namespace NetPulse\Check;

/**
 * Abstraction over external process execution. Checks that shell out
 * (ICMP ping) depend on this interface, so tests substitute a fake and
 * never spawn real processes.
 */
interface CommandRunner
{
    /**
     * @param list<string> $command command and its arguments, executed without a shell
     *
     * @return array{exitCode: int, stdout: string}
     */
    public function run(array $command): array;
}
