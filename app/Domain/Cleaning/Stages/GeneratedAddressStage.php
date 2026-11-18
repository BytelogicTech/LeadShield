<?php

namespace App\Domain\Cleaning\Stages;

use App\Domain\Cleaning\CleaningStage;
use App\Domain\Cleaning\LeadContext;

class GeneratedAddressStage implements CleaningStage
{
    public function key(): string
    {
        return 'generated_address';
    }

    public function handle(LeadContext $ctx): LeadContext
    {
        if ($ctx->stopped || $ctx->localPart === '') {
            return $ctx;
        }

        $local = $ctx->localPart;

        // name followed by 3 or more digits: claudiajpierson796
        if (preg_match('/[a-z]{4,}\d{3,}$/i', $local)) {
            $ctx->reject('generated_address', 'Generated-looking address');

            return $ctx;
        }

        // random-looking: long mixed alnum without separators, or many digits
        $digits = preg_match_all('/\d/', $local);
        $alpha = preg_match_all('/[a-z]/i', $local);
        if ($digits >= 4 && $alpha >= 4 && ! str_contains($local, '.') && ! str_contains($local, '_') && ! str_contains($local, '-')) {
            $ctx->reject('generated_address', 'Generated-looking address');
        }

        return $ctx;
    }
}
