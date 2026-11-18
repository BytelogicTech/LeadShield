<?php

return [

    'name' => env('LEADSHIELD_NAME', 'LeadShield'),

    'session_lifetime_minutes' => (int) env('LEADSHIELD_SESSION_LIFETIME', 525600),

    'retention' => [
        'purge_files_after_days' => (int) env('LEADSHIELD_PURGE_FILES_DAYS', 7),
        'preserve_suppressions' => true,
        'preserve_dns_cache' => true,
    ],

    'dns' => [
        'driver' => env('LEADSHIELD_DNS_DRIVER', 'system'),
        'canary_domains' => ['gmail.com', 'microsoft.com', 'google.com'],
        'domains_intel_ttl_days' => (int) env('LEADSHIELD_DOMAINS_INTEL_TTL', 7),
    ],

];
