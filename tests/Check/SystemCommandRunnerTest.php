<?php

declare(strict_types=1);

namespace NetPulse\Tests\Check;

use NetPulse\Check\SystemCommandRunner;
use PHPUnit\Framework\TestCase;

final class SystemCommandRunnerTest extends TestCase
{
    public function testCapturesStdoutAndExitCode(): void
    {
        $result = (new SystemCommandRunner())->run([PHP_BINARY, '-r', 'echo "ok";']);

        self::assertSame(0, $result['exitCode']);
        self::assertSame('ok', $result['stdout']);
    }

    public function testPropagatesNonZeroExitCode(): void
    {
        $result = (new SystemCommandRunner())->run([PHP_BINARY, '-r', 'exit(3);']);

        self::assertSame(3, $result['exitCode']);
    }
}
