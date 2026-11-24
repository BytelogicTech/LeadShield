@php
    /** @var \App\Models\ImportBatch $record */
    /** @var int $count */
    /** @var string $color */
    $pct = \App\Support\CleaningUi::metricPercent($record, $count);
    $label = number_format($count);
@endphp
<div class="min-w-[5.5rem]">
    <div class="flex items-baseline justify-end gap-1">
        <span class="text-sm font-semibold tabular-nums text-gray-950 dark:text-white">{{ $label }}</span>
        <span class="text-xs tabular-nums text-gray-500 dark:text-gray-400">({{ $pct }}%)</span>
    </div>
    <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
        <div
            class="h-full rounded-full transition-all duration-500
                @if ($color === 'success') bg-success-600
                @elseif ($color === 'warning') bg-warning-500
                @elseif ($color === 'purple') bg-purple-500
                @elseif ($color === 'danger') bg-danger-600
                @else bg-primary-600
                @endif
            "
            style="width: {{ max($count > 0 ? 2 : 0, min(100, $pct)) }}%"
        ></div>
    </div>
</div>
