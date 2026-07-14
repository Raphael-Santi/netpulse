<?php

declare(strict_types=1);

namespace NetPulse\Tests\Check;

use NetPulse\Check\DnsResolveCheck;
use NetPulse\Model\CheckType;
use NetPulse\Model\Target;
use PHPUnit\Framework\TestCase;

final class DnsResolveCheckTest extends TestCase
{
    public function testResolvesLocalhost(): void
    {
        $result = (new DnsResolveCheck())->check(self::target('localhost'));

        self::assertTrue($result->isUp());
    }

    public function testFailsOnUnresolvableHost(): void
    {
        // The .invalid TLD is reserved by RFC 2606 and never resolves.
        $result = (new DnsResolveCheck())->check(self::target('missing-host.invalid'));

        self::assertFalse($result->isUp());
        self::assertSame('DNS resolution failed', $result->error);
    }

    public function testIpAddressNeedsNoResolution(): void
    {
        $result = (new DnsResolveCheck())->check(self::target('127.0.0.1'));

        self::assertTrue($result->isUp());
        self::assertSame(0.0, $result->latencyMs);
    }

    private static function target(string $host): Target
    {
        return new Target(name: 'dns', type: CheckType::Dns, host: $host);
    }
}
