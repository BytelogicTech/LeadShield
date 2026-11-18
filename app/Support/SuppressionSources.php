<?php

namespace App\Support;

/**
 * Friendly labels for suppression `source` values (non-technical operators).
 */
class SuppressionSources
{
    public const LEADSHIELD = 'leadshield';

    public const SMARTLEAD = 'smartlead_export';

    public const LISTCLEAN = 'listclean';

    public const MANUAL = 'manual';

    public const IMPORT = 'import';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::LEADSHIELD => 'LeadShield cleaning',
            self::SMARTLEAD => 'Smartlead',
            self::LISTCLEAN => 'ListClean',
            self::MANUAL => 'Added manually',
            self::IMPORT => 'CSV / TXT import',
        ];
    }

    public static function label(string $source): string
    {
        return self::options()[$source] ?? str_replace('_', ' ', ucfirst($source));
    }
}
