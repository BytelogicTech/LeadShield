<?php

namespace App\Domain\Cleaning\Stages;

use App\Domain\Cleaning\CleaningStage;
use App\Domain\Cleaning\LeadContext;
use App\Domain\Dns\DnsResolver;

class SecureGatewayStage implements CleaningStage
{
    public function __construct(private DnsResolver $dns) {}

    public function key(): string
    {
        return 'secure_gateway';
    }

    public function handle(LeadContext $ctx): LeadContext
    {
        if ($ctx->stopped || $ctx->domain === '') {
            return $ctx;
        }

        $result = $this->dns->lookup($ctx->domain);
        $ctx->dnsStatus = $ctx->dnsStatus ?: $result->status;
        $ctx->mxProvider = $ctx->mxProvider ?: $result->mxProvider;

        if ($result->isSecureGateway) {
            $ctx->markGateway('gateway', 'Secure email gateway ('.$result->mxProvider.')');
        }

        return $ctx;
    }
}
