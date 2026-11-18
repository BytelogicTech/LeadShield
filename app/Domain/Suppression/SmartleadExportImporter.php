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

        $emailKey = $this->findHeader($headers, ['email', 'email address', 'work email']);
        if ($emailKey === null) {
            throw new \RuntimeException('Smartlead export needs an email column.');
        }

        $map = [
            'email' => $emailKey,
            'lead_status' => $this->findHeader($headers, ['lead_status', 'status']),
            'lead_category' => $this->findHeader($headers, ['lead_category', 'category']),
            'is_bounced' => $this->findHeader($headers, ['is_bounced', 'bounced']),
            'got_reply' => $this->findHeader($headers, ['got_reply', 'replied']),
            'sent_time' => $this->findHeader($headers, ['sent_time', 'sent_at']),
            'reply_message' => $this->findHeader($headers, ['reply_message', 'message', 'bounce_message']),
        ];

        foreach ($reader->getRows() as $row) {
            $stats['total']++;
            $normalized = $this->normalizeRow($row, $map);
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
    private function findHeader(array $headers, array $candidates): ?string
    {
        foreach ($candidates as $c) {
            if (in_array($c, $headers, true)) {
                return $c;
            }
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
