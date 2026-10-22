{{-- MCP piekļuve (round 3, docs/MCP.md): create / revoke personal access tokens for the in-app MCP server. --}}
<x-filament-panels::page>
    @php($rows = $this->rows())

    @if ($plaintext !== null)
        <x-filament::section :heading="__('admin.mcp.once_title', ['label' => $createdLabel])" icon="heroicon-o-key" icon-color="warning" data-testid="mcp-secret">
            <p class="text-sm">{{ __('admin.mcp.once_help') }}</p>
            <pre class="mt-2 overflow-x-auto rounded-lg bg-gray-100 p-3 text-sm dark:bg-white/5" data-testid="mcp-token"><code>{{ $plaintext }}</code></pre>
            <p class="mt-3 text-sm font-semibold">{{ __('admin.mcp.command_title') }}</p>
            <pre class="mt-1 overflow-x-auto rounded-lg bg-gray-100 p-3 text-sm dark:bg-white/5"><code>claude mcp add --transport http kaimini {{ $this->endpoint() }} --header "Authorization: Bearer {{ $plaintext }}"</code></pre>
            <div class="mt-3">
                <x-filament::button color="gray" wire:click="forgetSecret">{{ __('admin.mcp.copied') }}</x-filament::button>
            </div>
        </x-filament::section>
    @endif

    <x-filament::section :heading="__('admin.mcp.new')" :description="__('admin.mcp.help')">
        <form wire:submit="create" class="grid gap-4 md:grid-cols-3" data-testid="mcp-create">
            <label class="grid gap-1 text-sm">
                <span class="font-medium">{{ __('admin.mcp.label') }}</span>
                <x-filament::input.wrapper>
                    <x-filament::input type="text" wire:model="label" maxlength="60" required />
                </x-filament::input.wrapper>
            </label>
            <label class="grid gap-1 text-sm">
                <span class="font-medium">{{ __('admin.mcp.valid_days') }}</span>
                <x-filament::input.wrapper>
                    <x-filament::input type="number" wire:model="validDays" min="1" max="365" />
                </x-filament::input.wrapper>
                <span class="text-xs text-gray-600 dark:text-gray-300">{{ __('admin.mcp.valid_days_help') }}</span>
            </label>
            <label class="flex items-center gap-2 text-sm md:mt-6">
                <x-filament::input.checkbox wire:model.live="write" />
                <span>{{ __('admin.mcp.scope_write') }}</span>
            </label>
            <div class="md:col-span-3">
                <x-filament::button type="submit" icon="heroicon-o-plus">{{ __('admin.mcp.create') }}</x-filament::button>
                <span class="ms-2 text-xs text-gray-600 dark:text-gray-300">{{ __('admin.mcp.create_help') }}</span>
            </div>
        </form>
    </x-filament::section>

    <x-filament::section :heading="__('admin.mcp.list')">
        @if ($rows === [])
            <p class="text-sm" data-testid="mcp-empty">{{ __('admin.mcp.empty') }}</p>
        @else
            <ul class="grid gap-2 text-sm" data-testid="mcp-tokens">
                @foreach ($rows as $row)
                    <li class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 p-3 dark:border-white/10" wire:key="mcp-{{ $row['id'] }}">
                        <span class="grid gap-0.5">
                            <span class="font-semibold">{{ $row['label'] }} <span class="font-mono text-xs text-gray-600 dark:text-gray-300">…{{ $row['hint'] }}</span></span>
                            <span class="text-xs text-gray-600 dark:text-gray-300">
                                {{ __('admin.mcp.scopes') }}: {{ collect($row['scopes'])->map(fn ($s) => __('admin.mcp.scope.'.$s))->join(', ') }}
                                · {{ __('admin.mcp.created_at', ['date' => $row['created_at']->timezone('Europe/Riga')->format('d.m.Y H:i')]) }}
                                · {{ $row['expires_at'] !== null ? __('admin.mcp.expires', ['date' => $row['expires_at']->timezone('Europe/Riga')->format('d.m.Y')]) : __('admin.mcp.no_expiry') }}
                                · {{ $row['last_used_at'] !== null ? __('admin.mcp.last_used', ['date' => $row['last_used_at']->timezone('Europe/Riga')->format('d.m.Y H:i')]) : __('admin.mcp.never_used') }}
                            </span>
                        </span>
                        <span class="flex items-center gap-2">
                            <x-filament::badge :color="$row['state'] === 'active' ? 'success' : 'gray'">{{ __('admin.mcp.state.'.$row['state']) }}</x-filament::badge>
                            @if ($row['state'] === 'active')
                                <x-filament::button size="sm" color="danger" wire:click="revoke('{{ $row['id'] }}')" wire:confirm="{{ __('admin.mcp.revoke_confirm') }}">{{ __('admin.mcp.revoke') }}</x-filament::button>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-panels::page>
