<?php

namespace App\Domain\Cleaning\Stages;

use App\Domain\Cleaning\CleaningStage;
use App\Domain\Cleaning\LeadContext;

class DisposableStage implements CleaningStage
{
    public function key(): string
    {
        return 'disposable';
    }

    public function handle(LeadContext $ctx): LeadContext
    {
        if ($ctx->stopped || $ctx->domain === '') {
            return $ctx;
        }

        $fake = array_flip(config('cleaning.disposable_or_fake', []));
        if (isset($fake[$ctx->domain])) {
            $ctx->reject('disposable', 'Disposable / fake domain');
        }

        return $ctx;
    }
}
