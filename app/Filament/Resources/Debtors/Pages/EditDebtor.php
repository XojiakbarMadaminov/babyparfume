<?php

namespace App\Filament\Resources\Debtors\Pages;

use App\Models\Client;
use App\Models\Debtor;
use Filament\Actions\ViewAction;
use Filament\Actions\DeleteAction;
use Filament\Support\Exceptions\Halt;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use App\Filament\Resources\Debtors\DebtorResource;

class EditDebtor extends EditRecord
{
    protected static string $resource = DebtorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $client = $this->record->client;

        if ($client) {
            $data['phone']     = $client->phone;
            $data['full_name'] = $client->full_name;
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['store_id'] = auth()->user()?->current_store_id;

        $rawPhone = $data['phone'] ?? null;
        $fullName = $data['full_name'] ?? null;

        if (is_string($rawPhone)) {
            $digits = preg_replace('/\D/', '', (string) $rawPhone);

            if (!empty($digits)) {
                $last9     = substr($digits, -9);
                $canonical = '+998' . $last9;

                $existing = Client::withTrashed()
                    ->where('phone', $canonical)
                    ->first();

                if ($existing) {
                    // If found a different client with this phone, re-link debtor to that client
                    if ($this->record->client_id !== $existing->id) {
                        // Prevent linking if another debtor already exists for this client in this store with active debt
                        $hasActiveDebt = Debtor::query()
                            ->where('store_id', $data['store_id'])
                            ->where('client_id', $existing->id)
                            ->where('id', '!=', $this->record->id)
                            ->where('amount', '>', 0)
                            ->exists();

                        if ($hasActiveDebt) {
                            Notification::make()
                                ->title("Ushbu mijoz qarzdorlar ro'yxatida allaqachon mavjud")
                                ->danger()
                                ->persistent()
                                ->send();

                            throw new Halt;
                        }

                        if ($existing->trashed()) {
                            $existing->restore();
                        }

                        // Optionally update the existing client's name
                        if ($fullName !== null) {
                            $existing->update(['full_name' => $fullName]);
                        }

                        $data['client_id'] = $existing->id;
                    } else {
                        // Update current client's details
                        $this->record->client?->update([
                            'full_name' => $fullName,
                            'phone'     => $canonical,
                        ]);
                    }
                } else {
                    // Update current client's details (or create if somehow missing)
                    if ($this->record->client) {
                        $this->record->client->update([
                            'full_name' => $fullName,
                            'phone'     => $canonical,
                        ]);
                    } else {
                        $client = Client::create([
                            'full_name' => $fullName,
                            'phone'     => $canonical,
                        ]);

                        $data['client_id'] = $client->id;
                    }
                }
            }
        }

        unset($data['phone'], $data['full_name']);

        return $data;
    }
}
