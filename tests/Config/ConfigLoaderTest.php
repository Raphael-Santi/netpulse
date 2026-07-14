<?php

declare(strict_types=1);

namespace NetPulse\Tests\Config;

use InvalidArgumentException;
use NetPulse\Config\ConfigLoader;
use NetPulse\Model\CheckType;
use PHPUnit\Framework\TestCase;

final class ConfigLoaderTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testLoadsValidConfig(): void
    {
        $config = ConfigLoader::load($this->configFile(<<<'PHP'
            <?php

            return [
                'storage' => ['dsn' => 'sqlite::memory:'],
                'failure_threshold' => 2,
                'interval' => 30,
                'telegram' => ['token' => 'bot-token', 'chat_id' => '42'],
                'targets' => [
                    ['name' => 'db', 'type' => 'tcp', 'host' => '127.0.0.1', 'port' => 3306],
                    ['name' => 'site', 'type' => 'http', 'host' => 'example.com', 'tls' => true, 'path' => '/health'],
                    ['name' => 'resolver', 'type' => 'dns', 'host' => 'example.com'],
                    ['name' => 'gw', 'type' => 'ping', 'host' => '192.168.1.1', 'timeout' => 1.5],
                ],
            ];
            PHP));

        self::assertCount(4, $config->targets);
        self::assertSame('sqlite::memory:', $config->dsn);
        self::assertSame(2, $config->failureThreshold);
        self::assertSame(30, $config->intervalSeconds);
        self::assertSame('bot-token', $config->telegramToken);
        self::assertSame('42', $config->telegramChatId);

        [$db, $site, , $gw] = $config->targets;

        self::assertSame(CheckType::Tcp, $db->type);
        self::assertSame(3306, $db->port);
        self::assertTrue($site->tls);
        self::assertSame('/health', $site->path);
        self::assertSame(1.5, $gw->timeout);
    }

    public function testAppliesDefaultsWhenSectionsOmitted(): void
    {
        $config = ConfigLoader::load($this->configFile(<<<'PHP'
            <?php

            return [
                'targets' => [
                    ['name' => 'gw', 'type' => 'ping', 'host' => '192.168.1.1'],
                ],
            ];
            PHP));

        self::assertSame(3, $config->failureThreshold);
        self::assertSame(60, $config->intervalSeconds);
        self::assertStringContainsString('var/netpulse.db', $config->dsn);
        self::assertNull($config->telegramToken);
        self::assertSame(3.0, $config->targets[0]->timeout);
    }

    public function testEmptyTelegramTokenIsTreatedAsDisabled(): void
    {
        $config = ConfigLoader::load($this->configFile(<<<'PHP'
            <?php

            return [
                'telegram' => ['token' => '', 'chat_id' => ''],
                'targets' => [
                    ['name' => 'gw', 'type' => 'ping', 'host' => '192.168.1.1'],
                ],
            ];
            PHP));

        self::assertNull($config->telegramToken);
        self::assertNull($config->telegramChatId);
    }

    public function testMissingTargetsThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('targets');

        ConfigLoader::load($this->configFile('<?php return [];'));
    }

    public function testUnknownCheckTypeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown check type');

        ConfigLoader::load($this->configFile(<<<'PHP'
            <?php

            return [
                'targets' => [
                    ['name' => 'x', 'type' => 'smtp', 'host' => 'mail.local'],
                ],
            ];
            PHP));
    }

    public function testTcpTargetWithoutPortThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a "port"');

        ConfigLoader::load($this->configFile(<<<'PHP'
            <?php

            return [
                'targets' => [
                    ['name' => 'db', 'type' => 'tcp', 'host' => '127.0.0.1'],
                ],
            ];
            PHP));
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not found');

        ConfigLoader::load('/nonexistent/netpulse.php');
    }

    private function configFile(string $php): string
    {
        $file = tempnam(sys_get_temp_dir(), 'netpulse-config-');
        self::assertIsString($file);

        file_put_contents($file, $php);
        $this->tempFiles[] = $file;

        return $file;
    }
}
