{{--
    Shared 5-step CSV import (DB-003, ROUND2-SPEC §4.1): Augšupielāde → Kartēšana → Pārbaude → Izmēģinājums → Imports.
    Page = a Filament page using InteractsWithImportPipeline. No cell value is a Livewire property: rows render from
    the sealed run on every request (masked where sensitive).
--}}
<x-filament-panels::page>
    @php
        $missing = $this->gateMissing();
        $run = $missing === null ? $this->currentRun() : null;
        $step = $run === null ? 1 : $run->step();
        $kind = $this->importHandler()->kind();
        $steps = ['upload', 'mapping', 'preview', 'dry_run', 'commit'];
    @endphp

    <nav aria-label="{{ __('import.steps.label') }}" class="grid gap-2" data-testid="import-stepper">
        <ol class="flex flex-wrap items-center gap-2">
            @foreach ($steps as $i => $key)
                <li class="flex items-center gap-2 text-sm" @if ($i + 1 === $step) aria-current="step" @endif>
                    <span @class([
                        'inline-flex h-7 w-7 items-center justify-center rounded-full text-xs font-semibold',
                        'bg-primary-600 text-white' => $i + 1 === $step,
                        'bg-success-600 text-white' => $i + 1 < $step,
                        'bg-gray-200 text-gray-700 dark:bg-white/10 dark:text-gray-200' => $i + 1 > $step,
                    ])>{{ $i + 1 }}</span>
                    <span @class(['font-semibold' => $i + 1 === $step])>{{ __('import.steps.'.$key) }}</span>
                </li>
            @endforeach
        </ol>
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('import.steps.n_of', ['n' => $step, 'total' => 5]) }}</p>
    </nav>

    @if ($missing !== null)
        {{-- D6: below the gate there is no uploader at all. --}}
        <x-filament::section :heading="__('import.gate.title')" icon="heroicon-o-lock-closed" data-testid="import-gate-closed">
            <p class="text-sm">{{ __('import.gate.lead.'.$kind) }}</p>
            @if ($missing !== [])
                <p class="mt-3 text-sm font-semibold">{{ __('import.gate.missing') }}</p>
                <ul class="mt-1 list-disc ps-5 text-sm">
                    @foreach ($missing as $label)
                        <li>{{ $label }}</li>
                    @endforeach
                </ul>
            @endif
            <div class="mt-4">
                <x-filament::button tag="a" :href="$this->gateUrl()" icon="heroicon-o-arrow-right">{{ __('import.gate.open_wizard') }}</x-filament::button>
            </div>
        </x-filament::section>
    @elseif ($run === null)
        <x-filament::section :heading="__('import.upload.title')" icon="heroicon-o-arrow-up-tray">
            <p class="text-sm">{{ __('import.upload.lead') }}</p>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('import.upload.limits') }}</p>
            <p class="mt-2 text-sm">
                <x-filament::link :href="$this->templateUrl()" icon="heroicon-o-document-arrow-down">{{ __('import.template.download') }}</x-filament::link>
            </p>

            <form wire:submit="uploadFile" class="mt-4 grid gap-3">
                <label class="grid gap-1 text-sm font-medium" for="import-file">{{ __('import.upload.file') }}</label>
                <input id="import-file" type="file" wire:model="upload" accept=".csv,.txt,text/csv,text/plain"
                    x-on:livewire-upload-finish="$wire.uploadFile()"
                    class="block w-full max-w-full text-sm file:me-3 file:min-h-12 file:rounded-lg file:border-0 file:bg-gray-100 file:px-4 file:py-2 dark:file:bg-white/10">
                <div wire:loading wire:target="upload" class="text-sm">{{ __('import.upload.sending') }}</div>
                <div>
                    <x-filament::button type="submit" icon="heroicon-o-arrow-up-tray" wire:loading.attr="disabled" wire:target="upload,uploadFile">{{ __('import.upload.submit') }}</x-filament::button>
                </div>
            </form>

            <details class="mt-6">
                <summary class="cursor-pointer text-sm font-medium">{{ __('import.upload.paste_toggle') }}</summary>
                <form wire:submit="uploadPaste" class="mt-3 grid gap-3">
                    <label class="grid gap-1 text-sm font-medium">{{ __('import.upload.paste') }}
                        <textarea wire:model="paste" rows="8" autocomplete="off" spellcheck="false"
                            class="rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm dark:border-white/10 dark:bg-white/5"></textarea>
                    </label>
                    <div>
                        <x-filament::button type="submit" color="gray" icon="heroicon-o-clipboard-document">{{ __('import.upload.paste_submit') }}</x-filament::button>
                    </div>
                </form>
            </details>
        </x-filament::section>
    @else
        @php($fields = $this->importFields())

        @if ($run->status === \App\Models\ImportRun::UPLOADED)
            @php($m = $this->mappingData($run))
            <x-filament::section :heading="__('import.mapping.title')" icon="heroicon-o-arrows-right-left">
                @if ($m['previous'])
                    <div class="mb-4 rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm dark:border-warning-500/40 dark:bg-warning-500/10" role="status" data-testid="import-same-file">
                        {{ __('import.mapping.same_file', ['at' => $m['previous']->updated_at->timezone('Europe/Riga')->format('d.m. H:i')]) }}
                    </div>
                @endif

                <div class="grid gap-3 md:grid-cols-3">
                    <label class="grid gap-1 text-sm font-medium">{{ __('import.mapping.encoding') }}
                        <select wire:model="encoding" wire:change="reformat" class="min-h-12 rounded-lg border border-gray-300 px-3 dark:border-white/10 dark:bg-white/5">
                            @foreach (\App\Services\Import\CsvReader::ENCODINGS as $enc)
                                <option value="{{ $enc }}">{{ __('import.encoding.'.$enc) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="grid gap-1 text-sm font-medium">{{ __('import.mapping.delimiter') }}
                        <select wire:model="delimiter" wire:change="reformat" class="min-h-12 rounded-lg border border-gray-300 px-3 dark:border-white/10 dark:bg-white/5">
                            @foreach (array_keys(\App\Services\Import\CsvReader::DELIMITERS) as $d)
                                <option value="{{ $d }}">{{ __('import.delimiter.'.($d === ';' ? 'semicolon' : ($d === ',' ? 'comma' : 'tab'))) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="flex min-h-12 items-center gap-2 self-end text-sm">
                        <input type="checkbox" wire:model="hasHeader" wire:change="reformat"> {{ __('import.mapping.has_header') }}
                    </label>
                </div>
                @if ($run->encoding !== 'utf-8')
                    <p class="mt-2 text-sm text-warning-700 dark:text-warning-400" role="status">{{ __('import.mapping.encoding_check', ['encoding' => __('import.encoding.'.$run->encoding)]) }}</p>
                @endif
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('import.mapping.rows', ['rows' => $run->row_count]) }}</p>

                <div class="mt-4 grid gap-3">
                    @foreach ($m['headers'] as $i => $header)
                        <div class="grid gap-2 rounded-lg border border-gray-200 p-3 md:grid-cols-2 md:items-center dark:border-white/10">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold">{{ $header }}</p>
                                <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ __('import.mapping.sample') }}: {{ implode(' · ', $m['samples'][$i] ?? ['—']) }}</p>
                            </div>
                            <label class="grid gap-1 text-sm">
                                <span class="sr-only">{{ __('import.mapping.target', ['column' => $header]) }}</span>
                                <select wire:model="mapping.{{ $i }}" class="min-h-12 rounded-lg border border-gray-300 px-3 dark:border-white/10 dark:bg-white/5">
                                    <option value="">{{ __('import.mapping.ignored') }}</option>
                                    @foreach ($fields as $key => $field)
                                        <option value="{{ $key }}">{{ $field->label() }}{{ $field->required ? ' *' : '' }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>
                    @endforeach
                </div>

                <fieldset class="mt-4 grid gap-2 text-sm">
                    <legend class="font-medium">{{ __('import.mode.title') }}</legend>
                    <label class="flex min-h-12 items-center gap-2"><input type="radio" wire:model="mode" value="create_only"> {{ __('import.mode.create_only') }}</label>
                    <label class="flex min-h-12 items-center gap-2"><input type="radio" wire:model="mode" value="create_or_update"> {{ __('import.mode.create_or_update') }}</label>
                </fieldset>

                <div class="mt-4 flex flex-wrap gap-2">
                    <x-filament::button wire:click="saveMapping" icon="heroicon-o-arrow-right">{{ __('import.mapping.next') }}</x-filament::button>
                    <x-filament::button wire:click="discardRun" color="gray" icon="heroicon-o-x-mark">{{ __('import.discard') }}</x-filament::button>
                </div>
            </x-filament::section>
        @elseif (in_array($run->status, [\App\Models\ImportRun::MAPPED, \App\Models\ImportRun::VALIDATED], true))
            @php($p = $this->previewData($run))
            @if ($run->status === \App\Models\ImportRun::VALIDATED)
                @php($s = $run->summary)
                <x-filament::section :heading="__('import.dry_run.title')" icon="heroicon-o-beaker" data-testid="import-dry-run">
                    <p class="text-base font-semibold">{{ __('import.dry_run.counts', ['create' => $s['actions']['create'] ?? 0, 'update' => $s['actions']['update'] ?? 0, 'skip' => $s['actions']['skip'] ?? 0]) }}</p>
                    @if (($s['codes'] ?? []) !== [])
                        <ul class="mt-2 list-disc ps-5 text-sm">
                            @foreach ($s['codes'] as $code => $count)
                                <li>{{ __('import.code.'.$code) }} — {{ $count }}</li>
                            @endforeach
                        </ul>
                    @endif
                    <div class="mt-4 grid gap-2">
                        @foreach ($this->importHandler()->confirmations() as $key)
                            <label class="flex min-h-12 items-center gap-2 text-sm"><input type="checkbox" wire:model="confirmed.{{ $key }}"> {{ __('import.confirm.'.$key) }}</label>
                        @endforeach
                    </div>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('import.dry_run.valid_for', ['minutes' => (int) config('majas.import.dry_run_valid_minutes')]) }}</p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <x-filament::button wire:click="commitImport" icon="heroicon-o-check" wire:loading.attr="disabled">{{ __('import.commit.submit') }}</x-filament::button>
                        <x-filament::button wire:click="dryRun" color="gray" icon="heroicon-o-arrow-path">{{ __('import.dry_run.again') }}</x-filament::button>
                    </div>
                </x-filament::section>
            @endif

            <x-filament::section :heading="__('import.preview.title')" icon="heroicon-o-table-cells">
                @if ($p === null)
                    <p class="text-sm">{{ __('import.error.run_expired') }}</p>
                @else
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <label class="flex min-h-12 items-center gap-2 text-sm"><input type="checkbox" wire:model.live="onlyErrors"> {{ __('import.preview.only_problems') }}</label>
                        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('import.preview.page', ['page' => $p['page'], 'pages' => $p['pages'], 'total' => $p['total']]) }}</p>
                    </div>
                    <ul class="mt-3 grid gap-2" data-testid="import-preview">
                        @foreach ($p['rows'] as $row)
                            @php($status = $row->status($p['severity']))
                            <li class="grid gap-1 rounded-lg border border-gray-200 p-3 text-sm dark:border-white/10">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold">{{ __('import.preview.row', ['row' => $row->number]) }}</span>
                                    <x-filament::badge size="sm" :color="$status === 'ok' ? 'success' : ($status === 'warning' ? 'warning' : 'danger')"
                                        :icon="$status === 'ok' ? 'heroicon-o-check-circle' : ($status === 'warning' ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-x-circle')">
                                        {{ __('import.status.'.$status) }}
                                    </x-filament::badge>
                                    <x-filament::badge size="sm" color="gray">{{ __('import.action.'.$row->action) }}</x-filament::badge>
                                </div>
                                @if ($row->preview !== [])
                                    <p class="break-words text-gray-700 dark:text-gray-200">
                                        @foreach ($row->preview as $field => $value)
                                            @if ($value !== '')
                                                <span class="me-3"><span class="text-gray-500 dark:text-gray-400">{{ __('import.field.'.$field) }}:</span> {{ $value }}</span>
                                            @endif
                                        @endforeach
                                    </p>
                                @endif
                                @foreach ($row->codes as $code)
                                    <p class="text-gray-700 dark:text-gray-200">• {{ __('import.code.'.$code) }}</p>
                                @endforeach
                            </li>
                        @endforeach
                    </ul>
                    @if ($p['pages'] > 1)
                        <div class="mt-3 flex flex-wrap gap-2">
                            @if ($p['page'] > 1)
                                <x-filament::button size="sm" color="gray" wire:click="goToPage({{ $p['page'] - 1 }})">{{ __('import.preview.prev') }}</x-filament::button>
                            @endif
                            @if ($p['page'] < $p['pages'])
                                <x-filament::button size="sm" color="gray" wire:click="goToPage({{ $p['page'] + 1 }})">{{ __('import.preview.next') }}</x-filament::button>
                            @endif
                        </div>
                    @endif
                @endif

                <div class="mt-4 flex flex-wrap gap-2">
                    @if ($run->status === \App\Models\ImportRun::MAPPED)
                        <x-filament::button wire:click="dryRun" icon="heroicon-o-beaker">{{ __('import.dry_run.submit') }}</x-filament::button>
                    @endif
                    <x-filament::button wire:click="backToMapping" color="gray" icon="heroicon-o-arrow-left">{{ __('import.mapping.back') }}</x-filament::button>
                    <x-filament::button wire:click="discardRun" color="gray" icon="heroicon-o-x-mark">{{ __('import.discard') }}</x-filament::button>
                </div>
            </x-filament::section>
        @else
            @php($c = $run->committed_counts ?? [])
            <x-filament::section :heading="__('import.commit.title')" icon="heroicon-o-arrow-down-on-square-stack" data-testid="import-result">
                <div @if ($run->status === \App\Models\ImportRun::COMMITTING) wire:poll.2s @endif role="status">
                    @if ($run->status === \App\Models\ImportRun::COMMITTING)
                        <p class="text-base font-semibold">{{ __('import.commit.progress', ['done' => $c['processed'] ?? 0, 'total' => $run->row_count]) }}</p>
                    @elseif ($run->status === \App\Models\ImportRun::COMMITTED)
                        <p class="text-base font-semibold">{{ __('import.commit.done', ['created' => $c['created'] ?? 0, 'updated' => $c['updated'] ?? 0, 'skipped' => $c['skipped'] ?? 0]) }}</p>
                        @if ($kind === 'roster')
                            <p class="mt-2 text-sm">{{ __('import.commit.inform_reminder') }}</p>
                        @endif
                    @else
                        <p class="text-base font-semibold text-danger-600 dark:text-danger-400">{{ __('import.commit.failed', ['code' => __('import.error.'.($run->error_code ?? 'commit_failed'))]) }}</p>
                    @endif
                </div>
                @if ($run->status !== \App\Models\ImportRun::COMMITTING)
                    <div class="mt-4 flex flex-wrap gap-2">
                        <x-filament::button wire:click="startOver" icon="heroicon-o-arrow-up-tray">{{ __('import.commit.again') }}</x-filament::button>
                        @if (method_exists($this, 'backUrl'))
                            <x-filament::button tag="a" :href="$this->backUrl()" color="gray" icon="heroicon-o-arrow-left">{{ __('import.commit.back') }}</x-filament::button>
                        @endif
                    </div>
                @endif
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
