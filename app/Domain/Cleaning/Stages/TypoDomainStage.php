<?php

namespace App\Domain\Cleaning\Stages;

use App\Domain\Cleaning\CleaningStage;
use App\Domain\Cleaning\LeadContext;

class TypoDomainStage implements CleaningStage
{
    public function key(): string
    {
        return 'typo_domain';
    }

    public function handle(LeadContext $ctx): LeadContext
    {
        if ($ctx->stopped || $ctx->domain === '') {
            return $ctx;
        }

        $suggestion = $this->suggestion($ctx->domain);
        if ($suggestion !== null) {
            $ctx->suggestedFix = $suggestion;
            $ctx->reject('typo_domain', 'Typo domain (did you mean '.$suggestion.'?)');
        }

        return $ctx;
    }

    private function suggestion(string $domain): ?string
    {
        $targets = config('cleaning.free_providers', []);
        $typos = config('cleaning.known_typos', []);

        $closest = function (string $d) use ($targets): array {
            $best = null;
            $bestD = PHP_INT_MAX;
            foreach ($targets as $t) {
                $lv = levenshtein($d, (string) $t);
                if ($lv < $bestD) {
                    $bestD = $lv;
                    $best = (string) $t;
                }
            }

            return [$best, $bestD];
        };

        if (in_array($domain, $typos, true)) {
            return $closest($domain)[0] ?? null;
        }

        if (in_array($domain, $targets, true)) {
            return null;
        }

        $name = explode('.', $domain)[0];
        if (strlen($name) <= 10 && preg_match('/gm|hotm|outl|yah|iclo/', $name)) {
            [$best, $dist] = $closest($domain);
            if ($best !== null && $dist >= 1 && $dist <= 2) {
                return $best;
            }
        }

        return null;
    }
}
