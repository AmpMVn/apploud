<?php

declare(strict_types=1);

namespace App\Enum;

use InvalidArgumentException;

enum AccessLevel: int
{
    case GUEST = 10;
    case REPORTER = 20;
    case DEVELOPER = 30;
    case MAINTAINER = 40;
    case OWNER = 50;

    public function getLabel(): string
    {
        return match ($this) {
            self::GUEST => 'Host',
            self::REPORTER => 'Reporter',
            self::DEVELOPER => 'Vyvojar',
            self::MAINTAINER => 'Spravce',
            self::OWNER => 'Vlastnik',
        };
    }

    public function getStyle(): string
    {
        return match ($this) {
            self::GUEST => 'fg=gray',
            self::REPORTER => 'fg=blue',
            self::DEVELOPER => 'fg=green',
            self::MAINTAINER => 'fg=yellow',
            self::OWNER => 'fg=red',
        };
    }

    public static function fromInt(int $level): self
    {
        return self::tryFrom($level) ?? throw new InvalidArgumentException('Neplatná přístupová úroveň');
    }
}
