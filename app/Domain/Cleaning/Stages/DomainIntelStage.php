<?php

namespace App\Domain\Cleaning\Stages;

use App\Domain\Cleaning\CleaningStage;
use App\Domain\Cleaning\LeadContext;
use App\Domain\Dns\DnsResolver;

class DomainIntelStage implements CleaningStage
{
    public function __construct(private DnsResolver $dns) {}

    public function key(): string
    {
        return 'domain_intel';
    }

    public function handle(LeadContext $ctx): LeadContext
    {
        if ($ctx->stopped || $ctx->domain === '') {
            return $ctx;
        }

        $result = $this->dns->lookup($ctx->domain);
        $ctx->dnsStatus = $result->status;
        $ctx->mxProvider = $result->mxProvider;

        if ($result->status === 'nxdomain') {
            $ctx->reject('domain_nxdomain', 'Domain does not exist');
        } elseif (in_array($result->status, ['null_mx', 'no_mail'], true)) {
            $ctx->reject('domain_no_mail', 'Domain accepts no mail');
        } elseif ($result->status === 'no_mx_a_only') {
            $ctx->reject('domain_no_mx', 'No MX record');
        }

        return $ctx;
    }
}
