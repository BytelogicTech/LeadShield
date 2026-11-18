@php
    /** @var \App\Models\ImportBatch $record */
    $outputs = data_get($record->stats, 'output_paths', []);
@endphp

@if (is_array($outputs) && $outputs !== [])
    <div class="fi-ac flex flex-wrap gap-3">
        @if (filled($outputs['final'] ?? null))
            <x-filament::button color="success" wire:click="downloadOutput('final', 'clean_leads.csv')" size="sm">
                Download clean file
            </x-filament::button>
        @endif
        @if (filled($outputs['later'] ?? null))
            <x-filament::button color="warning" wire:click="downloadOutput('later', 'later.csv')" size="sm">
                Later CSV
            </x-filament::button>
        @endif
        @if (filled($outputs['gateway'] ?? null))
            <x-filament::button color="purple" wire:click="downloadOutput('gateway', 'gateway.csv')" size="sm">
                Gateway CSV
            </x-filament::button>
        @endif
        @if (filled($outputs['report'] ?? null))
            <x-filament::button color="gray" wire:click="downloadOutput('report', 'report.xlsx')" size="sm">
                Excel report
            </x-filament::button>
        @endif
    </div>
@endif
