<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\Attributes\Locked;
use App\Services\SmsBalanceService;
use Illuminate\Contracts\View\View;

class SmsBalanceIndicator extends Component
{
    #[Locked]
    public array $balance = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->canViewSmsBalance(), 403);
    }

    public function loadBalance(SmsBalanceService $balances): void
    {
        abort_unless(auth()->user()?->canViewSmsBalance(), 403);
        $this->balance = $balances->getBalance();
    }

    public function refreshBalance(SmsBalanceService $balances): void
    {
        abort_unless(auth()->user()?->canViewSmsBalance(), 403);
        $this->balance = $balances->getBalance(refresh: true);
    }

    public function render(): View
    {
        abort_unless(auth()->user()?->canViewSmsBalance(), 403);

        return view('livewire.sms-balance-indicator');
    }
}
