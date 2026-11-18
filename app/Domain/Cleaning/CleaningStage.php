<?php

namespace App\Domain\Cleaning;

interface CleaningStage
{
    public function key(): string;

    public function handle(LeadContext $ctx): LeadContext;
}
