<x-admin.layout title="Application logs">
    <x-admin.page-header title="Application logs"
        description="{{ $exists && $readable ? ($truncated ? 'Last ' . $lines . ' lines of ' : $lines . ' lines • ') . $size . ' • Updated ' . $updatedAt : '' }}">
        <x-admin.button :href="route('admin.logs.index')">
            <x-admin.icon name="activity" class="size-4" /> Refresh
        </x-admin.button>
    </x-admin.page-header>
    @if ($exists && !$readable)
        <x-admin.alert type="error" class="mb-6">
            The log file exists, but it cannot be read. Check the storage/logs folder permissions.
        </x-admin.alert>
    @endif
    <x-admin.card>
        <div class="bg-black p-4 max-h-[calc(100vh-12rem)] min-h-64 overflow-auto rounded-md">
            <pre class="text-white whitespace-pre-wrap break-words font-mono text-xs leading-6">{{ $readable && $logs !== '' ? $logs : 'No log entries yet.' }}
            </pre>
        </div>
    </x-admin.card>
</x-admin.layout>