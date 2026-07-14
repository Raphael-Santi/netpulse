<?php

declare(strict_types=1);

namespace NetPulse\Model;

enum CheckStatus: string
{
    case Up = 'up';
    case Down = 'down';
}
