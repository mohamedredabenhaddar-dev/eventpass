<?php

namespace App\Enums;

enum AccountStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
