<?php

namespace App\Domain\Cleaning\Stages;

use App\Domain\Cleaning\CleaningStage;
use App\Domain\Cleaning\LeadContext;

class SyntaxStage implements CleaningStage
{
    public function key(): string
    {
        return 'syntax';
    }

    public function handle(LeadContext $ctx): LeadContext
    {
        if ($ctx->stopped) {
            return $ctx;
        }

        if ($ctx->email === '') {
            $ctx->reject('invalid_format', 'Empty email');

            return $ctx;
        }

        $regex = (string) config('cleaning.email_regex');
        if (! preg_match($regex, $ctx->email)) {
            $ctx->reject('invalid_format', 'Invalid email format');
        }

        return $ctx;
    }
}
