<?php

namespace App\Domain\Import;

use App\Domain\Cleaning\EmailNormaliser;
use Spatie\SimpleExcel\SimpleExcelReader;

class ListCleanReader
{
    public function __construct(private EmailNormaliser $normaliser) {}

    /**
     * @return array<string, string> email => normalised status
     */
    public function load(string $absolutePath): array
    {
        $reader = SimpleExcelReader::create($absolutePath);
        $headers = $reader->getHeaders() ?? [];
        $lower = array_map(fn ($h) => strtolower(trim((string) $h)), $headers);

        $emailCol = $this->pick($headers, $lower, ['email', 'email address', 'e-mail']);
        $statusCol = $this->pick($headers, $lower, ['status', 'result', 'verdict', 'state', 'listclean status']);

        if ($emailCol === null || $statusCol === null) {
            throw new \RuntimeException(
                'ListClean file needs an email column and a status column. Found: '.implode(', ', $headers)
            );
        }

        $map = [];
        foreach ($reader->getRows() as $row) {
            $email = $this->normaliser->normalise($row[$emailCol] ?? '');
            if ($email === '') {
                continue;
            }
            $map[$email] = strtolower(trim((string) ($row[$statusCol] ?? '')));
        }

        return $map;
    }

    public function isClean(string $status): bool
    {
        return in_array($status, ['clean', 'valid', 'deliverable', 'ok', 'safe', 'verified', 'good'], true);
    }

    public function isDirty(string $status): bool
    {
        return in_array($status, [
            'dirty', 'invalid', 'undeliverable', 'do_not_mail', 'do not mail',
            'spamtrap', 'spam trap', 'abuse', 'unknown', 'catch-all', 'catchall',
            'risky', 'error',
        ], true) || ($status !== '' && ! $this->isClean($status));
    }

    /**
     * @param  list<string|null>  $headers
     * @param  list<string>  $lower
     * @param  list<string>  $candidates
     */
    private function pick(array $headers, array $lower, array $candidates): ?string
    {
        foreach ($candidates as $c) {
            $idx = array_search($c, $lower, true);
            if ($idx !== false) {
                return (string) $headers[$idx];
            }
        }

        foreach ($lower as $i => $h) {
            foreach ($candidates as $c) {
                if (str_contains($h, $c)) {
                    return (string) $headers[$i];
                }
            }
        }

        return null;
    }
}
