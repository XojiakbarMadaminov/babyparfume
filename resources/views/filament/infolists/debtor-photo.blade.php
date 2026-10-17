<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    @once
        @vite('resources/js/app.js')
    @endonce
    <div wire:ignore>
        <debtor-photo-viewer src="{{ route('debtors.photo', $getRecord(), false) }}"></debtor-photo-viewer>
    </div>
</x-dynamic-component>
