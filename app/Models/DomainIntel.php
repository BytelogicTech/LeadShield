<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $domain
 * @property array<int, string>|null $mx_hosts
 * @property string|null $mx_provider
 * @property bool $is_secure_gateway
 * @property string|null $dns_status
 * @property Carbon|null $expires_at
 */
class DomainIntel extends Model
{
    protected $table = 'domains_intel';

    protected $primaryKey = 'domain';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'domain',
        'mx_hosts',
        'mx_provider',
        'is_secure_gateway',
        'dns_status',
        'has_spf',
        'has_dmarc',
        'is_catch_all',
        'is_free_provider',
        'is_disposable',
        'registered_at',
        'checked_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'mx_hosts' => 'array',
            'is_secure_gateway' => 'boolean',
            'has_spf' => 'boolean',
            'has_dmarc' => 'boolean',
            'is_catch_all' => 'boolean',
            'is_free_provider' => 'boolean',
            'is_disposable' => 'boolean',
            'registered_at' => 'datetime',
            'checked_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
