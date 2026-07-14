<?php

declare(strict_types=1);

namespace NetPulse\Check;

/**
 * Runs a command via proc_open() with an argument array: no shell is
 * involved, so hostnames coming from the config can never be abused
 * for shell injection.
 */
final class SystemCommandRunner implements CommandRunner
{
    public function run(array $command): array
    {
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if ($process === false) {
            return ['exitCode' => 127, 'stdout' => ''];
        }

        $stdout = '';

        $stdoutPipe = $pipes[1] ?? null;
        $stderrPipe = $pipes[2] ?? null;

        if (is_resource($stdoutPipe)) {
            $contents = stream_get_contents($stdoutPipe);
            $stdout = $contents === false ? '' : $contents;
            fclose($stdoutPipe);
        }

        if (is_resource($stderrPipe)) {
            stream_get_contents($stderrPipe);
            fclose($stderrPipe);
        }

        return ['exitCode' => proc_close($process), 'stdout' => $stdout];
    }
}
