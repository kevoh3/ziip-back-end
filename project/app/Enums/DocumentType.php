<?php
namespace App\Enums;

enum DocumentType: string
{
    case NATIONAL_ID = '101';   // Kenya National ID
    case ALIEN_ID    = '102';   // Kenya Alien ID
    case PASSPORT    = '103';   // Passport

    public function label(): string
    {
        return match ($this) {
            self::NATIONAL_ID => 'National ID',
            self::ALIEN_ID    => 'Alien ID',
            self::PASSPORT    => 'Passport',
        };
    }
}
