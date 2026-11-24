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

    $clean = \App\Support\CleaningUi::finalCount($record);
    $later = \App\Support\CleaningUi::laterCount($record);
    $gateway = \App\Support\CleaningUi::gatewayCount($record);
    $removed = \App\Support\CleaningUi::removedCount($record);
    $metrics = [
        ['label' => 'Clean', 'count' => $clean, 'color' => 'bg-success-600'],
        ['label' => 'Later', 'count' => $later, 'color' => 'bg-warning-500'],
        ['label' => 'Gateway', 'count' => $gateway, 'color' => 'bg-purple-500'],
        ['label' => 'Removed', 'count' => $removed, 'color' => 'bg-danger-600'],
    ];
@endphp

<div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
    <div class="fi-section-content-ctn">
        <div class="fi-section-content p-6 space-y-5">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Cleaning in progress</p>
                    <p class="text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">{{ $label }}</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        You can leave this page — cleaning continues in the background.
                    </p>
                </div>
                <span class="text-sm font-medium text-primary-600 dark:text-primary-400">{{ $pctLabel }}</span>
            </div>

            <div class="h-2.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                <div
                    class="h-full rounded-full bg-primary-600 transition-all duration-500 dark:bg-primary-500"
                    style="width: {{ $hasTotal ? max(2, $pct) : 8 }}%"
                ></div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($metrics as $metric)
                    @php
                        $metricPct = \App\Support\CleaningUi::metricPercent($record, $metric['count']);
                    @endphp
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ $metric['label'] }}
                        </p>
                        <p class="mt-1 text-xl font-semibold tabular-nums text-gray-950 dark:text-white">
                            {{ number_format($metric['count']) }}
                            <span class="text-sm font-normal text-gray-500 dark:text-gray-400">({{ $metricPct }}%)</span>
                        </p>
                        <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                            <div
                                class="h-full rounded-full {{ $metric['color'] }} transition-all duration-500"
                                style="width: {{ max($metric['count'] > 0 ? 2 : 0, min(100, $metricPct)) }}%"
                            ></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
