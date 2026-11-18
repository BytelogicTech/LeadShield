<?php

namespace App\Support;

use App\Enums\LeadVerdict;
use App\Models\BatchLead;
use App\Models\DomainIntel;
use App\Models\ImportBatch;

class CleaningUi
{
    /** @var array<string, string> */
    public const REASON_LABELS = [
        'invalid_format' => 'Invalid email format',
        'duplicate' => 'Duplicate in file',
        'role_address' => 'Role address (info@, sales@...)',
        'typo_domain' => 'Typo in domain',
        'disposable' => 'Disposable / fake domain',
        'domain_nxdomain' => 'Domain does not exist',
        'domain_no_mail' => 'Domain does not accept email',
        'domain_no_mx' => 'No mail server (MX)',
        'generated_address' => 'Generated-looking address',
        'suppressed_email' => 'Already contacted / suppressed',
        'suppressed_domain' => 'Domain is blocked',
        'suppressed_domain_bounce' => 'Company domain had a hard bounce',
        'listclean_dirty' => 'ListClean: dirty',
        'listclean_missing' => 'ListClean: not in file',
        'gateway' => 'Secure email gateway',
        'later_company_cap' => 'Extra lead for company (later)',
        'safe' => 'Final',
    ];

    /** @var list<string> */
    public const REMOVAL_REASON_CODES = [
        'invalid_format',
        'duplicate',
        'role_address',
        'typo_domain',
        'disposable',
        'domain_nxdomain',
        'domain_no_mail',
        'domain_no_mx',
        'generated_address',
        'suppressed_email',
        'suppressed_domain',
        'suppressed_domain_bounce',
        'listclean_dirty',
        'listclean_missing',
    ];

    /** @var array<string, string|null> */
    private static array $mxCache = [];

    public static function reasonLabel(?string $code): string
    {
        if ($code === null || $code === '') {
            return '—';
        }

        return self::REASON_LABELS[$code] ?? str_replace('_', ' ', $code);
    }

    /**
     * @return array<string, string>
     */
    public static function reasonOptions(bool $forRemovalOnly = false): array
    {
        $codes = $forRemovalOnly ? self::REMOVAL_REASON_CODES : array_keys(self::REASON_LABELS);

        $options = [];
        foreach ($codes as $code) {
            $options[$code] = self::reasonLabel($code);
        }

        return $options;
    }

    public static function resultLabel(?LeadVerdict $verdict): string
    {
        return match ($verdict) {
            LeadVerdict::Safe => 'Final',
            LeadVerdict::Later => 'Later',
            LeadVerdict::Gateway => 'Gateway',
            LeadVerdict::Rejected => 'Removed',
            default => '—',
        };
    }

    public static function resultColor(?LeadVerdict $verdict): string
    {
        return match ($verdict) {
            LeadVerdict::Safe => 'success',
            LeadVerdict::Later => 'warning',
            LeadVerdict::Gateway => 'purple',
            LeadVerdict::Rejected => 'danger',
            default => 'gray',
        };
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'queued' => 'gray',
            'processing' => 'info',
            'completed' => 'success',
            'failed' => 'danger',
            default => 'gray',
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'queued' => 'Queued',
            'processing' => 'Processing',
            'completed' => 'Completed',
            'failed' => 'Failed',
            default => ucfirst($status),
        };
    }

    /**
     * @param  array<string, mixed>|null  $raw
     */
    public static function emailFromRaw(?array $raw): string
    {
        if ($raw === null) {
            return '';
        }

        $col = $raw['_email_col'] ?? 'email';

        return trim((string) ($raw[$col] ?? $raw['email'] ?? ''));
    }

    /**
     * @param  array<string, mixed>|null  $raw
     */
    public static function nameFromRaw(?array $raw): string
    {
        if ($raw === null) {
            return '';
        }

        if (filled($raw['name'] ?? null)) {
            return trim((string) $raw['name']);
        }

        if (filled($raw['full_name'] ?? null)) {
            return trim((string) $raw['full_name']);
        }

        $parts = array_filter([
            trim((string) ($raw['first_name'] ?? $raw['firstname'] ?? $raw['first'] ?? '')),
            trim((string) ($raw['last_name'] ?? $raw['lastname'] ?? $raw['last'] ?? '')),
        ]);

        return trim(implode(' ', $parts));
    }

    /**
     * @param  array<string, mixed>|null  $raw
     */
    public static function companyFromRaw(?array $raw): string
    {
        if ($raw === null) {
            return '';
        }

        return trim((string) ($raw['company'] ?? $raw['company_name'] ?? $raw['organization'] ?? $raw['org'] ?? ''));
    }

    public static function primaryReasonCode(BatchLead $lead): ?string
    {
        $reasons = $lead->reasons ?? [];

        return $reasons[0]['code'] ?? null;
    }

    public static function reasonDetail(BatchLead $lead): string
    {
        $reasons = $lead->reasons ?? [];
        if ($reasons === []) {
            return $lead->verdict === LeadVerdict::Safe ? 'Passed all checks' : '—';
        }

        $message = trim((string) ($reasons[0]['message'] ?? ''));
        if ($message !== '') {
            return $message;
        }

        $label = self::reasonLabel($reasons[0]['code'] ?? null);
        if (filled($lead->suggested_fix)) {
            return $label.' (did you mean '.$lead->suggested_fix.'?)';
        }

        return $label;
    }

    public static function mailProviderFor(BatchLead $lead): string
    {
        $email = self::emailFromRaw($lead->raw);
        if ($email === '' || ! str_contains($email, '@')) {
            return '—';
        }

        $domain = strtolower(substr($email, (int) strrpos($email, '@') + 1));
        if ($domain === '') {
            return '—';
        }

        if (! array_key_exists($domain, self::$mxCache)) {
            self::$mxCache[$domain] = DomainIntel::query()
                ->where('domain', $domain)
                ->value('mx_provider');
        }

        $provider = self::$mxCache[$domain];

        if (filled($provider)) {
            return (string) $provider;
        }

        $code = $lead->reasons[0]['code'] ?? null;
        $message = (string) (($lead->reasons[0]['message'] ?? ''));
        if ($code === 'gateway' && preg_match('/\(([^)]+)\)\s*$/', $message, $m) === 1) {
            return $m[1];
        }

        return '—';
    }

    public static function finalCount(ImportBatch $batch): int
    {
        return (int) data_get($batch->stats, 'totals.safe', 0);
    }

    public static function laterCount(ImportBatch $batch): int
    {
        return (int) data_get($batch->stats, 'totals.later', 0);
    }

    public static function gatewayCount(ImportBatch $batch): int
    {
        return (int) data_get($batch->stats, 'totals.gateway', 0);
    }

    public static function removedCount(ImportBatch $batch): int
    {
        return (int) data_get($batch->stats, 'totals.rejected', 0);
    }

    /**
     * @return list<array{code: string, label: string, count: int, percent: float}>
     */
    public static function removalReasonRows(ImportBatch $batch, bool $showZeros): array
    {
        $total = max(1, (int) $batch->total_rows);
        $counts = data_get($batch->stats, 'reason_counts', []);
        $listCleanUsed = (bool) data_get($batch->stats, 'listclean_used', filled($batch->listclean_path));

        $rows = [];
        foreach (self::REMOVAL_REASON_CODES as $code) {
            if (! $listCleanUsed && str_starts_with($code, 'listclean_')) {
                continue;
            }

            $count = (int) ($counts[$code] ?? 0);
            if (! $showZeros && $count === 0) {
                continue;
            }

            $rows[] = [
                'id' => $code,
                'code' => $code,
                'label' => self::reasonLabel($code),
                'count' => $count,
                'percent' => round(($count / $total) * 100, 1),
            ];
        }

        return $rows;
    }
}
