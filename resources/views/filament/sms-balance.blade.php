@if (auth()->user()?->canViewSmsBalance())
    @livewire('sms-balance-indicator')
@endif
