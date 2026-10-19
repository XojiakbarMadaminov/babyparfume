<?php

namespace App\Console\Commands;

use App\Models\GeneralSetting;
use Illuminate\Console\Command;

class ToggleSubscriptionWarning extends Command
{
    protected $signature = 'subscription:warning
                            {status? : on, off, or toggle}
                            {--text= : Custom warning message}';

    protected $description = 'Turn the mobile subscription warning ticker on or off';

    public function handle(): int
    {
        $settings = GeneralSetting::current();
        $status   = strtolower((string) $this->argument('status'));
        $text     = $this->option('text');

        if ($status === '') {
            $this->info('Current subscription warning status: ' . ($settings->subscription_warning_active ? 'ON' : 'OFF'));
            $this->line('Message: ' . $settings->subscription_warning_text);

            return Command::SUCCESS;
        }

        if ($status === 'on') {
            $settings->subscription_warning_active = true;
        } elseif ($status === 'off') {
            $settings->subscription_warning_active = false;
        } elseif ($status === 'toggle') {
            $settings->subscription_warning_active = ! $settings->subscription_warning_active;
        } else {
            $this->error('Unknown status argument. Use on, off, or toggle.');

            return Command::INVALID;
        }

        if (filled($text)) {
            $settings->subscription_warning_text = trim((string) $text);
        }

        $settings->save();

        $this->info('Subscription warning is now: ' . ($settings->subscription_warning_active ? 'ON' : 'OFF'));
        $this->line('Message: ' . $settings->subscription_warning_text);

        return Command::SUCCESS;
    }
}
