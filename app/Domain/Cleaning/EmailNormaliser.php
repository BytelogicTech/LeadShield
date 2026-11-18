<?php

namespace App\Domain\Cleaning;

class EmailNormaliser
{
    public function normalise(mixed $raw): string
    {
        $s = strtolower(trim((string) $raw));
        if (in_array($s, ['nan', 'none', 'null'], true)) {
            return '';
        }

        $s = str_replace('mailto:', '', $s);
        $s = (string) preg_replace('/[\s<>"\'(),;]/u', '', $s);

        return trim($s, '.');
    }

    public function duplicateKey(string $email): string
    {
        if (! str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);
        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $local = explode('+', $local, 2)[0];
            $local = str_replace('.', '', $local);

            return $local.'@gmail.com';
        }

        return $email;
    }

    public function baseLocalpart(string $local): string
    {
        $local = explode('+', $local, 2)[0];

        return preg_split('/[._-]/', $local, 2)[0] ?: $local;
    }
}
