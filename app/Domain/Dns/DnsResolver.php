<?php

namespace App\Domain\Dns;

use App\Models\DomainIntel;
use RuntimeException;

class DnsResolver
{
    /** @var array<string, DnsLookupResult> */
    private array $memory = [];

    public function assertDnsWorks(): void
    {
        foreach (config('leadshield.dns.canary_domains', ['gmail.com']) as $domain) {
            $result = $this->lookupFresh((string) $domain);
            if ($result->status === 'ok') {
                return;
            }
        }

        throw new RuntimeException(
            'DNS is not working on this server (could not resolve gmail.com / microsoft.com / google.com). Fix DNS, then try again. No leads were cleaned or removed.'
        );
    }

    public function lookup(string $domain): DnsLookupResult
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return new DnsLookupResult('nxdomain');
        }

        if (isset($this->memory[$domain])) {
            return $this->memory[$domain];
        }

        $cached = DomainIntel::query()->find($domain);
        if ($cached && $cached->expires_at !== null && now()->lt($cached->expires_at)) {
            /** @var list<string> $hosts */
            $hosts = is_array($cached->mx_hosts) ? $cached->mx_hosts : [];

            return $this->memory[$domain] = new DnsLookupResult(
                status: (string) $cached->dns_status,
                mxHosts: $hosts,
                mxProvider: $cached->mx_provider,
                isSecureGateway: (bool) $cached->is_secure_gateway,
            );
        }

        $result = $this->lookupFresh($domain);
        $this->store($domain, $result);

        return $this->memory[$domain] = $result;
    }

    public function lookupFresh(string $domain): DnsLookupResult
    {
        $domain = strtolower(trim($domain));

        $mx = @dns_get_record($domain.'.', DNS_MX);
        if (is_array($mx) && $mx !== []) {
            usort($mx, fn ($a, $b) => ($a['pri'] ?? 0) <=> ($b['pri'] ?? 0));
            $hosts = array_values(array_filter(array_map(
                fn ($r) => strtolower(rtrim((string) ($r['target'] ?? ''), '.')),
                $mx
            ), fn ($h) => $h !== '' && $h !== '.'));

            if ($hosts === []) {
                return new DnsLookupResult('null_mx');
            }

            $provider = $this->classifyMx($hosts);
            $gateways = array_flip(config('cleaning.secure_gateways', []));

            return new DnsLookupResult(
                status: 'ok',
                mxHosts: $hosts,
                mxProvider: $provider,
                isSecureGateway: $provider !== null && isset($gateways[$provider]),
            );
        }

        $a = @dns_get_record($domain.'.', DNS_A);
        if (is_array($a) && $a !== []) {
            return new DnsLookupResult('no_mx_a_only');
        }

        $ns = @dns_get_record($domain.'.', DNS_NS);
        $soa = @dns_get_record($domain.'.', DNS_SOA);
        if ((is_array($ns) && $ns !== []) || (is_array($soa) && $soa !== [])) {
            return new DnsLookupResult('no_mail');
        }

        return new DnsLookupResult('nxdomain');
    }

    /**
     * @param  list<string>  $hosts
     */
    public function classifyMx(array $hosts): ?string
    {
        $joined = strtolower(implode(' ', $hosts));
        foreach (config('cleaning.mx_providers', []) as $fragment => $name) {
            if (str_contains($joined, (string) $fragment)) {
                return (string) $name;
            }
        }

        return $hosts === [] ? null : 'Other';
    }

    private function store(string $domain, DnsLookupResult $result): void
    {
        $ttlDays = (int) config('leadshield.dns.domains_intel_ttl_days', 7);
        $free = array_flip(config('cleaning.free_providers', []));
        $fake = array_flip(config('cleaning.disposable_or_fake', []));

        DomainIntel::query()->updateOrCreate(
            ['domain' => $domain],
            [
                'mx_hosts' => $result->mxHosts,
                'mx_provider' => $result->mxProvider,
                'is_secure_gateway' => $result->isSecureGateway,
                'dns_status' => $result->status,
                'is_free_provider' => isset($free[$domain]),
                'is_disposable' => isset($fake[$domain]),
                'checked_at' => now(),
                'expires_at' => now()->addDays($ttlDays),
            ],
        );
    }
}
