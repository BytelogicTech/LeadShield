@php
    /** @var \App\Models\ImportBatch $record */
    $processed = (int) $record->processed_rows;
    $total = (int) $record->total_rows;
    $hasTotal = $total > 0;
    $pct = $hasTotal ? (int) min(100, floor(($processed / max(1, $total)) * 100)) : 0;
    $label = $hasTotal
        ? number_format($processed).' of '.number_format($total).' rows'
        : ($processed > 0 ? number_format($processed).' rows processed' : 'Preparing file…');
    $pctLabel = $hasTotal ? $pct.'%' : '…';
@endphp

<div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
    <div class="fi-section-content-ctn">
        <div class="fi-section-content p-6 space-y-3">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Cleaning in progress</p>
                    <p class="text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">{{ $label }}</p>
                    @if ($hasTotal)
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            You can leave this page — cleaning continues in the background.
                        </p>
                    @endif
                </div>
                <span class="text-sm font-medium text-primary-600 dark:text-primary-400">{{ $pctLabel }}</span>
            </div>
            <div class="h-2.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                <div
                    class="h-full rounded-full bg-primary-600 transition-all duration-500 dark:bg-primary-500"
                    style="width: {{ $hasTotal ? max(2, $pct) : 8 }}%"
                ></div>
            </div>
        </div>
    </div>
</div>
