{{--
    Permit issuer's list import (PARK-012): explanation card below gate L1, otherwise the 5-step pipeline. Preview cells
    are masked server-side; no cell value is bound to a Livewire property (paste goes as an action argument).
--}}
<x-filament-panels::page>
    @if (! $this->gateOpen())
        <x-filament::section :heading="__('admin.parking.import.closed_title')" icon="heroicon-o-lock-closed" icon-color="warning">
            <p class="text-sm">{{ __('admin.parking.import.closed_body') }}</p>
            @if ($this->missing() !== [])
                <p class="mt-3 text-sm font-semibold">{{ __('admin.parking.import.missing') }}</p>
                <ul class="mt-1 list-disc ps-5 text-sm">
                    @foreach ($this->missing() as $label)
                        <li>{{ $label }}</li>
                    @endforeach
                </ul>
            @endif
            <div class="mt-4">
                <x-filament::button tag="a" :href="$this->wizardUrl()" icon="heroicon-o-arrow-right">{{ __('admin.parking.import.open_wizard') }}</x-filament::button>
            </div>
        </x-filament::section>
    @else
        @php($step = $this->step())
        @php($run = $this->run())
        <nav aria-label="{{ __('admin.import.steps_label') }}" class="grid gap-2">
            <ol class="flex flex-wrap items-center gap-x-3 gap-y-2 text-sm">
                @foreach ([1 => 'upload', 2 => 'mapping', 3 => 'preview', 4 => 'dry_run', 5 => 'commit'] as $n => $key)
                    <li class="flex items-center gap-1.5" @if ($n === $step) aria-current="step" @endif>
                        <span @class([
                            'inline-flex h-7 w-7 items-center justify-center rounded-full text-xs font-bold',
                            'bg-primary-600 text-white' => $n === $step,
                            'bg-success-600 text-white' => $n < $step,
                            'bg-gray-200 text-gray-700 dark:bg-white/10 dark:text-gray-200' => $n > $step,
                        ])>@if ($n < $step)✓@else{{ $n }}@endif</span>
                        <span @class(['font-semibold' => $n === $step])>{{ __('admin.import.step.'.$key) }}</span>
                    </li>
                @endforeach
            </ol>
            <p class="text-xs text-gray-500">{{ __('admin.import.step_of', ['n' => $step, 'total' => 5]) }}</p>
        </nav>

        @if ($step === 1)
            <x-filament::section :heading="__('admin.parking.import.upload_title')" icon="heroicon-o-arrow-up-tray">
                <p class="text-sm">{{ __('admin.parking.import.upload_help') }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ __('admin.import.limits') }}</p>

                <form wire:submit="uploadFile" class="mt-4 grid gap-3">
                    <label class="grid gap-1 text-sm font-medium">{{ __('admin.import.file') }}
                        <input type="file" wire:model="upload" accept=".csv,.txt,text/csv,text/plain" class="text-sm">
                    </label>
                    @error('upload') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
                    <div><x-filament::button type="submit" icon="heroicon-o-arrow-up-tray" wire:loading.attr="disabled">{{ __('admin.import.upload') }}</x-filament::button></div>
                </form>

                <div class="mt-6 grid gap-2" x-data>
                    <label for="permit-paste" class="text-sm font-medium">{{ __('admin.import.paste') }}</label>
                    <textarea id="permit-paste" x-ref="paste" rows="8" class="rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm dark:border-white/10 dark:bg-white/5" autocomplete="off" spellcheck="false"></textarea>
                    <div>
                        <x-filament::button color="gray" icon="heroicon-o-clipboard-document"
                            x-on:click="$wire.uploadPaste($refs.paste.value).then(() => { $refs.paste.value = '' })">{{ __('admin.import.paste_submit') }}</x-filament::button>
                    </div>
                </div>

                <p class="mt-4 text-xs text-gray-500">{{ __('admin.parking.import.template_help') }}</p>
            </x-filament::section>
        @elseif ($step === 2 && $run)
            @if ($same = $this->sameFile())
                <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="warning" :heading="__('admin.import.same_file', ['at' => $same->updated_at->setTimezone('Europe/Riga')->format('d.m. H:i')])">
                    <p class="text-sm">{{ __('admin.import.same_file_help') }}</p>
                </x-filament::section>
            @endif

            <x-filament::section :heading="__('admin.import.format_title')" icon="heroicon-o-adjustments-horizontal">
                @if ($run->options['encoding_guessed'] ?? false)
                    <p class="mb-3 text-sm text-warning-700 dark:text-warning-400">{{ __('admin.import.encoding_guessed', ['encoding' => $run->encoding]) }}</p>
                @endif
                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="grid gap-1 text-sm">{{ __('admin.import.encoding') }}
                        <select wire:model="encoding" class="rounded-lg border border-gray-300 px-2 py-2 text-sm dark:border-white/10 dark:bg-white/5">
                            @foreach (\App\Services\Import\CsvReader::ENCODINGS as $enc)
                                <option value="{{ $enc }}">{{ strtoupper($enc) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="grid gap-1 text-sm">{{ __('admin.import.delimiter') }}
                        <select wire:model="delimiter" class="rounded-lg border border-gray-300 px-2 py-2 text-sm dark:border-white/10 dark:bg-white/5">
                            @foreach (array_keys(\App\Services\Import\CsvReader::DELIMITERS) as $d)
                                <option value="{{ $d }}">{{ __('admin.import.delimiter_'.($d === ';' ? 'semicolon' : ($d === ',' ? 'comma' : 'tab'))) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="flex items-center gap-2 text-sm sm:mt-6">
                        <input type="checkbox" wire:model="hasHeader"> {{ __('admin.import.has_header') }}
                    </label>
                </div>
                <div class="mt-3"><x-filament::button color="gray" size="sm" wire:click="saveFormat">{{ __('admin.import.apply_format') }}</x-filament::button></div>
            </x-filament::section>

            <x-filament::section :heading="__('admin.import.mapping_title')" icon="heroicon-o-arrows-right-left">
                <p class="text-sm">{{ __('admin.import.mapping_help', ['rows' => $run->row_count]) }}</p>
                <div class="mt-3 grid gap-3">
                    @foreach ($this->headerLabels() as $i => $label)
                        <label @class(['grid gap-1 rounded-lg border p-3 text-sm sm:grid-cols-2 sm:items-center', 'border-gray-200 dark:border-white/10', 'opacity-60' => ($columns[$i] ?? '') === ''])>
                            <span class="font-medium break-words">{{ $label !== '' ? $label : __('admin.import.column_n', ['n' => $i + 1]) }}</span>
                            <select wire:model.live="columns.{{ $i }}" class="min-h-12 rounded-lg border border-gray-300 px-2 py-2 text-sm dark:border-white/10 dark:bg-white/5">
                                <option value="">{{ __('admin.import.not_imported') }}</option>
                                @foreach ($this->targets() as $target => $t)
                                    <option value="{{ $target }}">{{ $t['label'] }}{{ $t['required'] ? ' *' : '' }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endforeach
                </div>
                <fieldset class="mt-4 grid gap-2 text-sm">
                    <legend class="font-medium">{{ __('admin.import.mode') }}</legend>
                    <label class="flex items-center gap-2"><input type="radio" value="create_only" wire:model="mode"> {{ __('admin.import.mode_create_only') }}</label>
                    <label class="flex items-center gap-2"><input type="radio" value="create_or_update" wire:model="mode"> {{ __('admin.parking.import.mode_update') }}</label>
                </fieldset>
                <p class="mt-2 text-xs text-gray-500">{{ __('admin.import.required_note') }}</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <x-filament::button wire:click="saveMapping" icon="heroicon-o-arrow-right">{{ __('admin.import.next') }}</x-filament::button>
                    <x-filament::button color="gray" wire:click="discard" wire:confirm="{{ __('admin.import.discard_confirm') }}">{{ __('admin.import.discard') }}</x-filament::button>
                </div>
            </x-filament::section>
        @elseif (in_array($step, [3, 4], true) && $run)
            @php($preview = $this->preview())
            @if ($preview)
                @php($c = $preview['counts'])
                @if ($step === 4)
                    <x-filament::section :heading="__('admin.import.dry_run_title')" icon="heroicon-o-beaker">
                        <p class="text-base font-semibold">{{ __('admin.import.dry_run_counts', ['create' => $run->summary['create'] ?? 0, 'update' => $run->summary['update'] ?? 0, 'skip' => $run->summary['skip'] ?? 0]) }}</p>
                        <p class="mt-1 text-xs text-gray-500">{{ __('admin.import.dry_run_valid') }}</p>
                        <label class="mt-4 flex items-start gap-2 text-sm">
                            <input type="checkbox" wire:model="endorsed" class="mt-1"> <span>{{ __('admin.parking.import.endorsed') }}</span>
                        </label>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <x-filament::button wire:click="commit" icon="heroicon-o-check" wire:loading.attr="disabled">{{ __('admin.import.commit') }}</x-filament::button>
                            <x-filament::button color="gray" wire:click="dryRun" icon="heroicon-o-arrow-path">{{ __('admin.import.dry_run_again') }}</x-filament::button>
                            <x-filament::button color="gray" wire:click="editMapping">{{ __('admin.import.edit_mapping') }}</x-filament::button>
                            <x-filament::button color="gray" wire:click="discard" wire:confirm="{{ __('admin.import.discard_confirm') }}">{{ __('admin.import.discard') }}</x-filament::button>
                        </div>
                    </x-filament::section>
                @endif

                <x-filament::section :heading="__('admin.import.preview_title')" icon="heroicon-o-table-cells">
                    <p class="text-sm">{{ __('admin.import.preview_counts', ['ok' => $c['ok'], 'warning' => $c['warning'], 'error' => $c['error']]) }}</p>
                    <label class="mt-2 flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="onlyErrors"> {{ __('admin.import.only_problems') }}</label>

                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full min-w-[36rem] text-left text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 dark:border-white/10">
                                    <th class="py-2 pe-3">{{ __('admin.import.row') }}</th>
                                    <th class="py-2 pe-3">{{ __('admin.import.status') }}</th>
                                    @foreach ($this->targets() as $target => $t)
                                        @if (in_array($target, $columns, true))
                                            <th class="py-2 pe-3">{{ $t['label'] }}</th>
                                        @endif
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($preview['rows'] as $row)
                                    <tr class="border-b border-gray-100 align-top dark:border-white/5">
                                        <td class="py-2 pe-3 tabular-nums">{{ $row['line'] }}</td>
                                        <td class="py-2 pe-3">
                                            <x-filament::badge :color="match ($row['status']) { 'ok' => 'success', 'warning' => 'warning', default => 'danger' }"
                                                :icon="match ($row['status']) { 'ok' => 'heroicon-o-check-circle', 'warning' => 'heroicon-o-exclamation-triangle', default => 'heroicon-o-x-circle' }">
                                                {{ __('admin.import.row_status.'.$row['status']) }}
                                            </x-filament::badge>
                                            @foreach ($row['codes'] as $code)
                                                <p class="mt-1 text-xs">{{ __('admin.import.code.'.$code) }}</p>
                                            @endforeach
                                        </td>
                                        @foreach ($this->targets() as $target => $t)
                                            @if (in_array($target, $columns, true))
                                                <td class="py-2 pe-3 {{ $target === 'plate' ? 'font-mono' : '' }}">{{ $row['cells'][$target] ?? '' }}</td>
                                            @endif
                                        @endforeach
                                    </tr>
                                @empty
                                    <tr><td colspan="12" class="py-3 text-sm text-gray-500">{{ __('admin.import.no_rows') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if ($preview['pages'] > 1)
                        <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                            <x-filament::button size="sm" color="gray" wire:click="setPage({{ $preview['page'] - 1 }})" :disabled="$preview['page'] <= 1">{{ __('admin.import.prev') }}</x-filament::button>
                            <span>{{ __('admin.import.page_of', ['page' => $preview['page'], 'pages' => $preview['pages']]) }}</span>
                            <x-filament::button size="sm" color="gray" wire:click="setPage({{ $preview['page'] + 1 }})" :disabled="$preview['page'] >= $preview['pages']">{{ __('admin.import.next_page') }}</x-filament::button>
                        </div>
                    @endif

                    @if ($step === 3)
                        <div class="mt-4 flex flex-wrap gap-2">
                            <x-filament::button wire:click="dryRun" icon="heroicon-o-beaker">{{ __('admin.import.dry_run') }}</x-filament::button>
                            <x-filament::button color="gray" wire:click="editMapping">{{ __('admin.import.edit_mapping') }}</x-filament::button>
                            <x-filament::button color="gray" wire:click="discard" wire:confirm="{{ __('admin.import.discard_confirm') }}">{{ __('admin.import.discard') }}</x-filament::button>
                        </div>
                    @endif
                </x-filament::section>
            @endif
        @elseif ($step === 5 && $run)
            @php($counts = $run->committed_counts ?? [])
            <div @if ($run->status === \App\Enums\ImportStatus::Committing) wire:poll.2s @endif>
            <x-filament::section :heading="__('admin.import.result_title')" icon="heroicon-o-clipboard-document-check">
                @if ($run->status === \App\Enums\ImportStatus::Committing)
                    <p class="text-sm" role="status">{{ __('admin.import.committing', ['done' => $counts['processed'] ?? 0, 'total' => $run->row_count]) }}</p>
                @elseif ($run->status === \App\Enums\ImportStatus::Committed)
                    <p class="text-base font-semibold" role="status">{{ __('admin.import.committed', ['created' => $counts['created'] ?? 0, 'updated' => $counts['updated'] ?? 0, 'skipped' => $counts['skipped'] ?? 0]) }}</p>
                    @if (($counts['report'] ?? []) !== [])
                        <ul class="mt-3 list-disc ps-5 text-sm">
                            @foreach ($counts['report'] as $line)
                                <li>{{ __('admin.parking.import.row', ['row' => $line['row']]) }}: {{ implode(', ', array_map(fn ($code) => __('admin.import.code.'.$code), $line['codes'])) }}</li>
                            @endforeach
                        </ul>
                    @endif
                @else
                    <p class="text-sm text-danger-600" role="alert">{{ __('admin.import.error.'.($run->error_code ?? 'commit_failed')) }}</p>
                @endif
                @if ($run->status !== \App\Enums\ImportStatus::Committing)
                    <div class="mt-4"><x-filament::button color="gray" wire:click="startOver">{{ __('admin.import.start_over') }}</x-filament::button></div>
                @endif
            </x-filament::section>
            </div>
        @endif
    @endif
</x-filament-panels::page>
