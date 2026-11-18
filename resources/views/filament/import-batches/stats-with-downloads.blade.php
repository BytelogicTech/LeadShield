@php
    use Filament\Support\View\ComponentAttributeBag;

    /** @var \App\Filament\Resources\ImportBatches\Widgets\ImportBatchStatsOverview $widget */
    $pollingInterval = $widget->getPollingIntervalForView();
@endphp

<div class="space-y-4 w-full">
    <x-filament-widgets::widget
        :attributes="
            (new ComponentAttributeBag)
                ->merge([
                    'wire:poll.' . $pollingInterval => $pollingInterval ? true : null,
                ], escape: false)
                ->class([
                    'fi-wi-stats-overview',
                ])
        "
    >
        {{ $widget->getPublicContent() }}
    </x-filament-widgets::widget>

    @include('filament.import-batches.downloads', ['record' => $record])
</div>
