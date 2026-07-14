<?php

declare(strict_types=1);

namespace NetPulse\Tests\Check;

use NetPulse\Check\CheckFactory;
use NetPulse\Check\DnsResolveCheck;
use NetPulse\Check\HttpCheck;
use NetPulse\Check\PingCheck;
use NetPulse\Check\TcpConnectCheck;
use NetPulse\Model\CheckType;
use PHPUnit\Framework\TestCase;

final class CheckFactoryTest extends TestCase
{
    public function testCreatesMatchingCheckForEveryType(): void
    {
        $factory = new CheckFactory();

        self::assertInstanceOf(TcpConnectCheck::class, $factory->create(CheckType::Tcp));
        self::assertInstanceOf(HttpCheck::class, $factory->create(CheckType::Http));
        self::assertInstanceOf(DnsResolveCheck::class, $factory->create(CheckType::Dns));
        self::assertInstanceOf(PingCheck::class, $factory->create(CheckType::Ping));
    }
}
