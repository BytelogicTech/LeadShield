<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class CleaningRule extends Model
{
    use BelongsToWorkspace, LogsActivity;

    protected $fillable = [
        'workspace_id',
        'key',
        'enabled',
        'params',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'params' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }
}
