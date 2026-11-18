<?php

namespace App\Enums;

enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Manager = 'manager';
    case Sdr = 'sdr';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Manager => 'Manager',
            self::Sdr => 'SDR',
        };
    }

    public function canManageRules(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Manager], true);
    }

    public function canDeleteSuppressions(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Manager], true);
    }
}
