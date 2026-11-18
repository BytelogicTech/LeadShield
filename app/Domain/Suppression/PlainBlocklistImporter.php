<?php

namespace App\Domain\Suppression;

use App\Domain\Cleaning\EmailNormaliser;
use App\Enums\SuppressionReason;
use App\Enums\SuppressionType;
use Spatie\SimpleExcel\SimpleExcelReader;

class PlainBlocklistImporter
{
    public function __construct(
        private SuppressionWriter $writer,
        private EmailNormaliser $normaliser,
    ) {}

    /**
     * @return array{created: int, updated: int, skipped: int, total: int}
     */
    public function import(string $absolutePath, int $workspaceId, ?int $userId = null): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'total' => 0];
        $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        $values = $ext === 'txt'
            ? $this->fromTxt($absolutePath)
            : $this->fromTable($absolutePath);

        foreach ($values as $raw) {
            $stats['total']++;
            $raw = trim((string) $raw);
            if ($raw === '') {
                $stats['skipped']++;

                continue;
            }

            if (str_starts_with($raw, '@') || (! str_contains($raw, '@') && str_contains($raw, '.'))) {
                $domain = strtolower(ltrim($raw, '@'));
                $result = $this->writer->upsert(
                    workspaceId: $workspaceId,
                    type: SuppressionType::Domain,
                    value: $domain,
                    reason: SuppressionReason::Manual,
                    source: 'import',
                    createdBy: $userId,
                );
            } else {
                $email = $this->normaliser->normalise($raw);
                if ($email === '' || ! str_contains($email, '@')) {
                    $stats['skipped']++;

                    continue;
                }
                $result = $this->writer->upsert(
                    workspaceId: $workspaceId,
                    type: SuppressionType::Email,
                    value: $email,
                    reason: SuppressionReason::Manual,
                    source: 'import',
                    createdBy: $userId,
                );
            }

            $stats['created'] += $result['created'];
            $stats['updated'] += $result['updated'];
            $stats['skipped'] += $result['skipped'];
        }

        return $stats;
    }

    /**
     * @return list<string>
     */
    private function fromTxt(string $path): array
    {
        $raw = (string) file_get_contents($path);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;

        return preg_split('/\R/', $raw) ?: [];
    }

    /**
     * @return list<string>
     */
    private function fromTable(string $path): array
    {
        $reader = SimpleExcelReader::create($path);
        $headers = $reader->getHeaders() ?? [];
        $emailCol = null;
        foreach ($headers as $h) {
            if (str_contains(strtolower((string) $h), 'email') || str_contains(strtolower((string) $h), 'domain')) {
                $emailCol = (string) $h;
                break;
            }
        }
        $emailCol ??= (string) ($headers[0] ?? 'email');

        $values = [];
        foreach ($reader->getRows() as $row) {
            $values[] = (string) ($row[$emailCol] ?? reset($row) ?: '');
        }

        return $values;
    }
}
