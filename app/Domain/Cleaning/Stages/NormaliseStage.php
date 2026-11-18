<?php

namespace App\Domain\Cleaning\Stages;

use App\Domain\Cleaning\CleaningStage;
use App\Domain\Cleaning\EmailNormaliser;
use App\Domain\Cleaning\LeadContext;

class NormaliseStage implements CleaningStage
{
    public function __construct(private EmailNormaliser $normaliser) {}

    public function key(): string
    {
        return 'normalise';
    }

    public function handle(LeadContext $ctx): LeadContext
    {
        $emailCol = $ctx->raw['_email_col'] ?? 'email';
        $ctx->email = $this->normaliser->normalise($ctx->raw[$emailCol] ?? '');
        if ($ctx->email !== '' && str_contains($ctx->email, '@')) {
            $at = strrpos($ctx->email, '@');
            $ctx->localPart = substr($ctx->email, 0, $at);
            $ctx->domain = substr($ctx->email, $at + 1);
            $ctx->duplicateKey = $this->normaliser->duplicateKey($ctx->email);
        }

        return $ctx;
    }
}
