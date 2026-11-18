<?php

use App\Domain\Import\LeadFileReader;
use App\Filament\Resources\ImportBatches\ImportBatchResource;
use Illuminate\Support\Facades\Storage;

it('detects multiple email-like columns and ranks the best first', function () {
    Storage::fake('local');
    $relative = 'imports/tmp/multi_email.csv';
    Storage::disk('local')->put($relative, implode("\n", [
        'Name,Work Email,Personal Email,Company',
        'Ada,ada@acme.com,ada.home@gmail.com,Acme',
        'Bob,bob@acme.com,bob.home@gmail.com,Acme',
        'Cara,cara@acme.com,cara.home@gmail.com,Acme',
    ])."\n");

    $absolute = Storage::disk('local')->path($relative);
    $reader = new LeadFileReader;
    $headers = $reader->headers($absolute);
    $samples = $reader->sampleRows($absolute, 10);
    $candidates = $reader->detectEmailColumns($headers, $samples);

    expect($candidates)->toContain('Work Email')
        ->and($candidates)->toContain('Personal Email')
        ->and($candidates[0])->toBeIn(['Work Email', 'Personal Email']);
});

it('counts data rows excluding the header', function () {
    Storage::fake('local');
    $relative = 'imports/tmp/count.csv';
    Storage::disk('local')->put($relative, "email,name\na@x.com,A\nb@x.com,B\nc@x.com,C\n");

    $reader = new LeadFileReader;
    expect($reader->countRows(Storage::disk('local')->path($relative)))->toBe(3);
});

it('builds Filament email column options from a stored upload path', function () {
    Storage::fake('local');
    $relative = 'imports/tmp/whois.csv';
    Storage::disk('local')->put($relative, implode("\n", [
        'domain_name,registrant_name,registrant_email',
        'acme.com,Ada,ada@gmail.com',
    ])."\n");

    $options = ImportBatchResource::emailColumnOptions($relative);

    expect($options)->toHaveKey('registrant_email')
        ->and($options['registrant_email'])->toContain('recommended')
        ->and(ImportBatchResource::recommendedEmailColumn($relative))
        ->toBe('registrant_email');
});
