<?php

namespace App\Domain\Cleaning\Stages;

use App\Domain\Cleaning\CleaningStage;
use App\Domain\Cleaning\LeadContext;

class InFileDuplicateStage implements CleaningStage
{
    /** @var array<string, true> */
    private array $seen = [];

    public function key(): string
    {
        return 'in_file_duplicate';
    }

    public function reset(): void
    {
        $this->seen = [];
    }

    public function handle(LeadContext $ctx): LeadContext
    {
        if ($ctx->stopped || $ctx->duplicateKey === '') {
            return $ctx;
        }

        if (isset($this->seen[$ctx->duplicateKey])) {
            $ctx->reject('duplicate', 'Duplicate in file');

            return $ctx;
        }

        $this->seen[$ctx->duplicateKey] = true;

        return $ctx;
    }
}
