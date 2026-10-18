<x-filament-panels::page>
    <x-filament::section :heading="__('admin.parking.import.format_title')" icon="heroicon-o-information-circle">
        <p class="text-sm">{{ __('admin.parking.import.format') }}</p>
        <pre class="mt-2 overflow-x-auto rounded-lg bg-gray-50 p-3 text-xs dark:bg-white/5">numurs;veids;auto_numurs;dzivoklis;no;lidz;izsniedza
P-001;pamata;AB1234;145;01.04.2026;31.03.2027;Caurlaižu izsniedzējs
V-001;viesu;;145;01.04.2026;31.03.2027;Caurlaižu izsniedzējs</pre>
        <p class="mt-2 text-xs text-gray-500">{{ __('admin.parking.import.example_note') }}</p>
    </x-filament::section>

    <form wire:submit="import" class="grid gap-4">
        <label class="grid gap-1 text-sm font-medium">{{ __('admin.parking.import.csv') }}
            <textarea wire:model="csv" rows="10" class="rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm dark:border-white/10 dark:bg-white/5" autocomplete="off" spellcheck="false"></textarea>
        </label>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" wire:model="endorsed"> {{ __('admin.parking.import.endorsed') }}
        </label>
        <div>
            <x-filament::button type="submit" icon="heroicon-o-arrow-up-tray">{{ __('admin.parking.import.run') }}</x-filament::button>
        </div>
    </form>

    @if ($report)
        <x-filament::section :heading="__('admin.parking.import.report')">
            <p class="text-sm">{{ __('admin.parking.import.summary', ['permits' => $report['permits'], 'vehicles' => $report['vehicles'], 'skipped' => count($report['skipped'])]) }}</p>
            @if ($report['skipped'] !== [])
                <ul class="mt-2 list-disc ps-5 text-sm">
                    @foreach ($report['skipped'] as $s)
                        <li>{{ __('admin.parking.import.row', ['row' => $s['row']]) }}: {{ __('admin.parking.error.'.$s['reason']) }}</li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
