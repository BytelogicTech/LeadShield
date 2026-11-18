<?php

namespace App\Domain\Suppression;

use App\Domain\Cleaning\EmailNormaliser;
use App\Enums\SuppressionType;
use Spatie\SimpleExcel\SimpleExcelReader;

class SmartleadExportImporter
{
    public function __construct(
        private SuppressionWriter $writer,
        private SmartleadReasonClassifier $classifier,
        private EmailNormaliser $normaliser,
    ) {}

    /**
     * @return array{created: int, updated: int, skipped: int, total: int}
     */
    public function import(string $absolutePath, int $workspaceId, ?int $userId = null): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'total' => 0];
        $reader = SimpleExcelReader::create($absolutePath);
        $headers = array_map(fn ($h) => strtolower(trim((string) $h)), $reader->getHeaders() ?? []);

        $emailKey = $this->findHeader($headers, ['email', 'email address', 'work email'], exactFirst: true);
        if ($emailKey === null) {
            throw new \RuntimeException('Smartlead export needs an email column.');
        }

        // Reject accidental WHOIS / non-campaign CSVs (e.g. registrant_email only).
        if (in_array('domain_name', $headers, true) && ! in_array('is_bounced', $headers, true) && ! in_array('bounced', $headers, true)) {
            throw new \RuntimeException('This file does not look like a Smartlead campaign export (missing is_bounced / Bounce column).');
        }

        $bounceKey = $this->findHeader($headers, ['is_bounced', 'bounced', 'bounce'], exactFirst: true);
        if ($bounceKey === null) {
            throw new \RuntimeException('Smartlead export needs an is_bounced / Bounce column. Only bounced rows are imported.');
        }

        $map = [
            'email' => $emailKey,
            'lead_status' => $this->findHeader($headers, ['lead_status', 'status'], exactFirst: true),
            'lead_category' => $this->findHeader($headers, ['lead_category', 'category'], exactFirst: true),
            'is_bounced' => $bounceKey,
            'got_reply' => $this->findHeader($headers, ['got_reply', 'replied'], exactFirst: true),
            'sent_time' => $this->findHeader($headers, ['sent_time', 'sent_at'], exactFirst: true),
            'reply_message' => $this->findHeader($headers, ['reply_message', 'message', 'bounce_message'], exactFirst: true),
        ];

        foreach ($reader->getRows() as $row) {
            $stats['total']++;
            $normalized = $this->normalizeRow($row, $map);

            // Only bounced leads become suppressions from a Smartlead export.
            if (! $this->classifier->isBounced($normalized)) {
                $stats['skipped']++;

                continue;
            }

            $email = $this->normaliser->normalise($normalized['email'] ?? '');
            if ($email === '' || ! str_contains($email, '@')) {
                $stats['skipped']++;

                continue;
            }

            [$reason, $bounce] = $this->classifier->classify($normalized);
            $result = $this->writer->upsert(
                workspaceId: $workspaceId,
                type: SuppressionType::Email,
                value: $email,
                reason: $reason,
                source: 'smartlead_export',
                bounceDetail: $bounce,
                note: filled($normalized['sent_time'] ?? null) ? 'sent_time: '.$normalized['sent_time'] : null,
                createdBy: $userId,
            );

            $stats['created'] += $result['created'];
            $stats['updated'] += $result['updated'];
            $stats['skipped'] += $result['skipped'];
        }

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string|null>  $map
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row, array $map): array
    {
        $lower = [];
        foreach ($row as $k => $v) {
            $lower[strtolower(trim((string) $k))] = $v;
        }

        $out = [];
        foreach ($map as $field => $header) {
            $out[$field] = $header !== null ? ($lower[$header] ?? '') : '';
        }

        return $out;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string>  $candidates
     */
    private function findHeader(array $headers, array $candidates, bool $exactFirst = false): ?string
    {
        foreach ($candidates as $c) {
            if (in_array($c, $headers, true)) {
                return $c;
            }
        }

        // Avoid matching registrant_email / similar when looking for "email".
        if ($exactFirst) {
            return null;
        }

        foreach ($headers as $h) {
            foreach ($candidates as $c) {
                if (str_contains($h, $c)) {
                    return $h;
                }
            }
        }

        return null;
    }
}
