<?php

namespace App\Domain\Dns;

class DnsLookupResult
{
    /**
     * @param  list<string>  $mxHosts
     */
    public function __construct(
        public string $status,
        public array $mxHosts = [],
        public ?string $mxProvider = null,
        public bool $isSecureGateway = false,
    ) {}
}
