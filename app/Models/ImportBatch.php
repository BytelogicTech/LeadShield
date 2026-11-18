<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class ImportBatch extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id',
        'user_id',
        'lead_source_id',
        'original_filename',
        'storage_path',
        'listclean_path',
        'column_mapping',
        'status',
        'dns_status',
        'total_rows',
        'processed_rows',
        'stats',
        'started_at',
        'finished_at',
    ];

    protected static function booted(): void
    {
        static::deleting(function (ImportBatch $batch): void {
            $paths = array_filter([
                $batch->storage_path,
                $batch->listclean_path,
                ...array_values(data_get($batch->stats, 'output_paths', []) ?: []),
            ]);

            foreach ($paths as $path) {
                if (is_string($path) && $path !== '' && Storage::disk('local')->exists($path)) {
                    Storage::disk('local')->delete($path);
                }
            }

            $dir = 'imports/'.$batch->id;
            if (Storage::disk('local')->exists($dir)) {
                Storage::disk('local')->deleteDirectory($dir);
            }
        });
    }

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
