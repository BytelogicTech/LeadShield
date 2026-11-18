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
     * @param  list<string>  $headers
     * @param  list<array<string, mixed>>  $sampleRows
     */
    public function detectEmailColumn(array $headers, array $sampleRows = [], ?string $preferred = null): string
    {
        if ($preferred && in_array($preferred, $headers, true)) {
            return $preferred;
        }

        $exact = ['email', 'e-mail', 'email address', 'work email', 'email_address'];
        $cands = array_values(array_filter(
            $headers,
            fn ($h) => str_contains(strtolower((string) $h), 'email') || str_contains(strtolower((string) $h), 'e-mail')
        ));

        foreach ($cands as $c) {
            if (in_array(strtolower(trim((string) $c)), $exact, true)) {
                return (string) $c;
            }
        }

        if ($cands !== []) {
            return (string) $cands[0];
        }

        $best = null;
        $bestN = 0;
        foreach ($headers as $h) {
            $n = 0;
            foreach ($sampleRows as $r) {
                if (str_contains((string) ($r[$h] ?? ''), '@')) {
                    $n++;
                }
            }
            if ($n > $bestN) {
                $best = (string) $h;
                $bestN = $n;
            }
        }

        if ($best === null) {
            throw new \RuntimeException('Could not find an email column. Columns: '.implode(', ', $headers));
        }

        return $best;
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
