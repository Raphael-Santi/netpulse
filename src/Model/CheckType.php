<?php

declare(strict_types=1);

namespace NetPulse\Model;

enum CheckType: string
{
    case Tcp = 'tcp';
    case Http = 'http';
    case Dns = 'dns';
    case Ping = 'ping';
}
