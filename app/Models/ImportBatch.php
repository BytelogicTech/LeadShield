<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id',
        'user_id',
        'lead_source_id',
        'original_filename',
        'storage_path',
        'column_mapping',
        'status',
        'dns_status',
        'total_rows',
        'processed_rows',
        'stats',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'column_mapping' => 'array',
            'stats' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<LeadSource, $this> */
    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class);
    }

    /** @return HasMany<BatchLead, $this> */
    public function batchLeads(): HasMany
    {
        return $this->hasMany(BatchLead::class);
    }
}
