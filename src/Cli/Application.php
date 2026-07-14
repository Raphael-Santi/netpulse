<?php

declare(strict_types=1);

namespace NetPulse\Cli;

use InvalidArgumentException;
use NetPulse\Check\CheckFactory;
use NetPulse\Config\Config;
use NetPulse\Config\ConfigLoader;
use NetPulse\Incident\IncidentDetector;
use NetPulse\Model\CheckOutcome;
use NetPulse\Monitor;
use NetPulse\Notifier\StderrNotifier;
use NetPulse\Notifier\TelegramNotifier;
use NetPulse\Storage\PdoStorage;

/**
 * Thin CLI layer: parses arguments, wires the object graph and renders
 * plain-text output. Exit codes make `netpulse check` scriptable from
 * cron and shell: 0 — all targets up, 1 — something is down or an
 * incident is open, 2 — configuration or usage error.
 */
final class Application
{
    private const string USAGE = <<<TXT
        netpulse — lightweight network availability monitor

        Usage:
          netpulse check  [--config=path]   run all checks once and print results
          netpulse watch  [--config=path]   keep running checks in a loop (daemon mode)
          netpulse status [--config=path]   show last known state and open incidents

        The config defaults to ./config/targets.php.

        TXT;

    private bool $running = true;

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        [$command, $configPath] = $this->parseArguments($argv);

        if ($command === 'help') {
            fwrite(STDOUT, self::USAGE);

            return 0;
        }

        try {
            $config = ConfigLoader::load($configPath);
        } catch (InvalidArgumentException $exception) {
            fwrite(STDERR, 'Config error: ' . $exception->getMessage() . PHP_EOL);

            return 2;
        }

        $storage = PdoStorage::fromDsn($config->dsn, $config->dbUser, $config->dbPassword);
        $storage->migrate();

        $notifier = $config->telegramToken !== null && $config->telegramChatId !== null
            ? new TelegramNotifier($config->telegramToken, $config->telegramChatId)
            : new StderrNotifier();

        $detector = new IncidentDetector($storage, $notifier, $config->failureThreshold);
        $monitor = new Monitor($config->targets, new CheckFactory(), $storage, $detector);

        return match ($command) {
            'check' => $this->runChecks($monitor),
            'watch' => $this->watch($monitor, $config->intervalSeconds),
            'status' => $this->showStatus($config, $storage),
            default => $this->unknownCommand($command),
        };
    }

    /**
     * @param list<string> $argv
     *
     * @return array{0: string, 1: string}
     */
    private function parseArguments(array $argv): array
    {
        $cwd = getcwd();
        $configPath = ($cwd === false ? '.' : $cwd) . '/config/targets.php';
        $positional = [];

        foreach (array_slice($argv, 1) as $argument) {
            if (str_starts_with($argument, '--config=')) {
                $configPath = substr($argument, strlen('--config='));
            } elseif ($argument === '--help' || $argument === '-h') {
                return ['help', $configPath];
            } else {
                $positional[] = $argument;
            }
        }

        return [$positional[0] ?? 'help', $configPath];
    }

    private function runChecks(Monitor $monitor): int
    {
        $outcomes = $monitor->runOnce();
        $this->render($outcomes);

        foreach ($outcomes as $outcome) {
            if (!$outcome->result->isUp()) {
                return 1;
            }
        }

        return 0;
    }

    private function watch(Monitor $monitor, int $intervalSeconds): int
    {
        // Graceful shutdown: under systemd SIGTERM lets the current cycle
        // finish instead of killing the process mid-write. Without ext-pcntl
        // the loop is simply terminated by the signal, which is also safe —
        // every cycle is a complete transaction of its own.
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);

            $stop = function (): void {
                $this->running = false;
            };

            pcntl_signal(SIGTERM, $stop);
            pcntl_signal(SIGINT, $stop);
        }

        fwrite(STDOUT, sprintf('netpulse: checking every %d s%s', $intervalSeconds, PHP_EOL));

        while ($this->running) {
            $this->render($monitor->runOnce());

            for ($i = 0; $i < $intervalSeconds && $this->running; $i++) {
                sleep(1);
            }
        }

        fwrite(STDOUT, 'netpulse: stopped' . PHP_EOL);

        return 0;
    }

    private function showStatus(Config $config, PdoStorage $storage): int
    {
        fwrite(STDOUT, sprintf(
            '%-20s %-24s %-6s %12s  %s%s',
            'TARGET',
            'CHECK',
            'STATE',
            'LATENCY',
            'CHECKED AT',
            PHP_EOL,
        ));

        foreach ($config->targets as $target) {
            $last = $storage->lastResult($target->name);

            fwrite(STDOUT, sprintf(
                '%-20s %-24s %-6s %12s  %s%s',
                $target->name,
                $target->describe(),
                $last === null ? '—' : strtoupper($last->status->value),
                $last?->latencyMs === null ? '—' : sprintf('%.1f ms', $last->latencyMs),
                $last === null ? 'never' : $last->checkedAt->format('Y-m-d H:i:s'),
                PHP_EOL,
            ));
        }

        $incidents = $storage->openIncidents();

        if ($incidents === []) {
            return 0;
        }

        fwrite(STDOUT, PHP_EOL . 'Open incidents:' . PHP_EOL);

        foreach ($incidents as $incident) {
            fwrite(STDOUT, sprintf(
                '  %s — down since %s%s',
                $incident->targetName,
                $incident->openedAt->format('Y-m-d H:i:s'),
                PHP_EOL,
            ));
        }

        return 1;
    }

    private function unknownCommand(string $command): int
    {
        fwrite(STDERR, sprintf('Unknown command "%s".%s', $command, PHP_EOL) . self::USAGE);

        return 2;
    }

    /**
     * @param list<CheckOutcome> $outcomes
     */
    private function render(array $outcomes): void
    {
        foreach ($outcomes as $outcome) {
            $result = $outcome->result;

            fwrite(STDOUT, sprintf(
                '%-20s %-24s %-6s %s%s',
                $outcome->target->name,
                $outcome->target->describe(),
                $result->isUp() ? 'UP' : 'DOWN',
                $result->isUp()
                    ? sprintf('%.1f ms', $result->latencyMs ?? 0.0)
                    : ($result->error ?? ''),
                PHP_EOL,
            ));
        }
    }
}
