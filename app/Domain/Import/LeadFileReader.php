<?php

namespace App\Domain\Import;

use Spatie\SimpleExcel\SimpleExcelReader;

class LeadFileReader
{
    /**
     * @return array{0: list<string>, 1: \Generator<int, array<string, mixed>>}
     */
    public function stream(string $absolutePath): array
    {
        $reader = SimpleExcelReader::create($absolutePath);
        $headers = $reader->getHeaders() ?? [];

        return [$headers, $this->rows($reader)];
    }

    /**
     * @return list<string>
     */
    public function headers(string $absolutePath): array
    {
        return SimpleExcelReader::create($absolutePath)->getHeaders() ?? [];
    }

    /**
     * Count data rows (excludes header). Used so progress can show x / y (pct%).
     * CSV/TXT uses a fast line scan; XLSX still streams via SimpleExcel.
     */
    public function countRows(string $absolutePath): int
    {
        $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        if (in_array($ext, ['csv', 'txt'], true)) {
            $handle = fopen($absolutePath, 'rb');
            if ($handle === false) {
                return 0;
            }

            $count = 0;
            $header = fgetcsv($handle);
            if ($header === false) {
                fclose($handle);

                return 0;
            }

            while (fgetcsv($handle) !== false) {
                $count++;
            }
            fclose($handle);

            return $count;
        }

        $count = 0;
        [, $rows] = $this->stream($absolutePath);
        foreach ($rows as $_) {
            $count++;
        }

        return $count;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sampleRows(string $absolutePath, int $limit = 25): array
    {
        $samples = [];
        [, $rows] = $this->stream($absolutePath);
        foreach ($rows as $row) {
            $samples[] = $row;
            if (count($samples) >= $limit) {
                break;
            }
        }

        return $samples;
    }

    /**
     * Find every column that looks like it contains emails, ranked best-first.
     *
     * @param  list<string>  $headers
     * @param  list<array<string, mixed>>  $sampleRows
     * @return list<string>
     */
    public function detectEmailColumns(array $headers, array $sampleRows = []): array
    {
        if ($headers === []) {
            return [];
        }

        $exact = ['email', 'e-mail', 'email address', 'work email', 'email_address', 'emailaddress'];
        $scored = [];

        foreach ($headers as $header) {
            $name = (string) $header;
            $lower = strtolower(trim($name));
            $score = 0;

            if (in_array($lower, $exact, true)) {
                $score += 100;
            } elseif (str_contains($lower, 'email') || str_contains($lower, 'e-mail')) {
                $score += 50;
            }

            $hits = 0;
            foreach ($sampleRows as $row) {
                $value = trim((string) ($row[$name] ?? ''));
                if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $hits++;
                } elseif (str_contains($value, '@') && str_contains($value, '.')) {
                    $hits++;
                }
            }

            if ($hits > 0) {
                $score += $hits * 10;
            }

            if ($score > 0) {
                $scored[$name] = $score;
            }
        }

        if ($scored === []) {
            // Fall back: any column with @ in samples, even without "email" in the name.
            foreach ($headers as $header) {
                $name = (string) $header;
                $hits = 0;
                foreach ($sampleRows as $row) {
                    if (str_contains((string) ($row[$name] ?? ''), '@')) {
                        $hits++;
                    }
                }
                if ($hits > 0) {
                    $scored[$name] = $hits;
                }
            }
        }

        arsort($scored);

        return array_keys($scored);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<string, mixed>>  $sampleRows
     */
    public function detectEmailColumn(array $headers, array $sampleRows = [], ?string $preferred = null): string
    {
        if ($preferred && in_array($preferred, $headers, true)) {
            return $preferred;
        }

        $candidates = $this->detectEmailColumns($headers, $sampleRows);
        if ($candidates !== []) {
            return $candidates[0];
        }

        throw new \RuntimeException('Could not find an email column. Columns: '.implode(', ', $headers));
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function rows(SimpleExcelReader $reader): \Generator
    {
        $i = 0;
        foreach ($reader->getRows() as $row) {
            $i++;
            yield $i => $row;
        }
    }
}
