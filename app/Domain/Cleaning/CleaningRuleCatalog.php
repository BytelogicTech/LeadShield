<?php

namespace App\Domain\Cleaning;

class CleaningRuleCatalog
{
    /**
     * Default cleaning rules seeded per workspace.
     *
     * @return list<array{key: string, label: string, description: string, enabled: bool, params: array<string, mixed>}>
     */
    public static function defaults(): array
    {
        return [
            [
                'key' => 'normalise',
                'label' => 'Normalise email',
                'description' => 'Lowercase, trim, and parse local-part / domain.',
                'enabled' => true,
                'params' => [],
            ],
            [
                'key' => 'syntax',
                'label' => 'Valid email format',
                'description' => 'Reject addresses that fail the email format check.',
                'enabled' => true,
                'params' => [],
            ],
            [
                'key' => 'in_file_duplicate',
                'label' => 'Duplicates in file',
                'description' => 'Keep the first occurrence; remove later duplicates in the same upload.',
                'enabled' => true,
                'params' => [],
            ],
            [
                'key' => 'role_address',
                'label' => 'Role addresses',
                'description' => 'Remove info@, sales@, support@ and similar non-personal inboxes.',
                'enabled' => true,
                'params' => [],
            ],
            [
                'key' => 'typo_domain',
                'label' => 'Typo domains',
                'description' => 'Catch common misspellings (gmial.com → gmail.com).',
                'enabled' => true,
                'params' => [],
            ],
            [
                'key' => 'disposable',
                'label' => 'Disposable / fake domains',
                'description' => 'Remove temp-mail and placeholder domains.',
                'enabled' => true,
                'params' => [],
            ],
            [
                'key' => 'listclean',
                'label' => 'ListClean file',
                'description' => 'When a ListClean export is attached, keep only clean emails.',
                'enabled' => true,
                'params' => [],
            ],
            [
                'key' => 'suppression',
                'label' => 'Suppression list',
                'description' => 'Remove emails/domains already on the workspace suppression list.',
                'enabled' => true,
                'params' => [],
            ],
            [
                'key' => 'generated_address',
                'label' => 'Generated-looking addresses',
                'description' => 'Flag random / auto-generated local parts.',
                'enabled' => true,
                'params' => [],
            ],
            [
                'key' => 'domain_intel',
                'label' => 'Domain DNS / MX',
                'description' => 'Reject domains with no DNS or no mail servers.',
                'enabled' => true,
                'params' => [],
            ],
            [
                'key' => 'secure_gateway',
                'label' => 'Secure email gateway',
                'description' => 'Route Mimecast / Proofpoint / etc. leads to the Gateway list.',
                'enabled' => true,
                'params' => [],
            ],
            [
                'key' => 'per_company_cap',
                'label' => 'Per-company domain cap',
                'description' => 'Extra leads for the same company domain go to Later.',
                'enabled' => true,
                'params' => [
                    'max_per_company_domain' => (int) config('cleaning.max_per_company_domain', 1),
                ],
            ],
        ];
    }

    public static function label(string $key): string
    {
        foreach (self::defaults() as $row) {
            if ($row['key'] === $key) {
                return $row['label'];
            }
        }

        return str_replace('_', ' ', $key);
    }

    public static function description(string $key): string
    {
        foreach (self::defaults() as $row) {
            if ($row['key'] === $key) {
                return $row['description'];
            }
        }

        return '';
    }
}
