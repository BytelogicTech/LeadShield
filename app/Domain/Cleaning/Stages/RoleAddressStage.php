<?php

namespace App\Domain\Cleaning\Stages;

use App\Domain\Cleaning\CleaningStage;
use App\Domain\Cleaning\EmailNormaliser;
use App\Domain\Cleaning\LeadContext;

class RoleAddressStage implements CleaningStage
{
    public function __construct(private EmailNormaliser $normaliser) {}

    public function key(): string
    {
        return 'role_address';
    }

    public function handle(LeadContext $ctx): LeadContext
    {
        if ($ctx->stopped) {
            return $ctx;
        }

        $roles = array_flip(config('cleaning.role_localparts', []));
        $local = strtolower($ctx->localPart);
        $base = $this->normaliser->baseLocalpart($local);

        if (isset($roles[$local]) || isset($roles[$base])) {
            $ctx->reject('role_address', 'Role / group address');
        }

        return $ctx;
    }
}
