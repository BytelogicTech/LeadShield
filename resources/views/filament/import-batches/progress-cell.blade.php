@php
    /** @var \App\Models\ImportBatch $record */
    $done = (int) $record->processed_rows;
    $total = (int) $record->total_rows;
    $hasTotal = $total > 0;
    $pct = $hasTotal ? (int) min(100, floor(($done / max(1, $total)) * 100)) : 0;

    if ($record->status === 'queued') {
        $title = 'Waiting in queue…';
        $bar = 8;
    } elseif ($record->status === 'processing' && ! $hasTotal) {
        $title = 'Counting rows…';
        $bar = 8;
    } elseif (in_array($record->status, ['queued', 'processing'], true)) {
        $title = number_format($done).' / '.number_format($total).' ('.$pct.'%)';
        $bar = max(2, $pct);
    } elseif ($record->status === 'completed') {
        $title = number_format($total).' rows';
        $bar = 100;
    } elseif ($record->status === 'failed') {
        $title = 'Failed';
        $bar = 0;
    } else {
        $title = '—';
        $bar = 0;
    }
@endphp
<div class="min-w-[9rem]">
    <div class="text-sm font-medium tabular-nums text-gray-950 dark:text-white">{{ $title }}</div>
    @if (in_array($record->status, ['queued', 'processing', 'completed'], true))
        <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
            <div
                class="h-full rounded-full transition-all duration-500
                    {{ $record->status === 'completed' ? 'bg-success-600' : 'bg-primary-600' }}"
                style="width: {{ $bar }}%"
            ></div>
        </div>
    @endif
</div>
