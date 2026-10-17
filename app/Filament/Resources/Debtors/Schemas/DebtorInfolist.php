<?php

namespace App\Filament\Resources\Debtors\Schemas;

use App\Models\Debtor;
use Filament\Schemas\Schema;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;

class DebtorInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                ViewEntry::make('photo_path')
                    ->label('Qarzdor rasmi')
                    ->view('filament.infolists.debtor-photo')
                    ->visible(fn (Debtor $record): bool => filled($record->photo_path))
                    ->columnSpanFull(),
                TextEntry::make('client.full_name')
                    ->label('To`liq ism'),
                TextEntry::make('client.phone')
                    ->label('Telefon raqam'),
                TextEntry::make('amount')
                    ->label('Qarz summasi')
                    ->numeric(),
                TextEntry::make('currency')
                    ->label('Valyuta'),
                TextEntry::make('date')
                    ->label('Qarz sanasi')
                    ->dateTime(),
                TextEntry::make('note')
                    ->label('Izoh'),
                TextEntry::make('created_at')
                    ->label('Yaratilgan')
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->label('Yangilangan')
                    ->dateTime(),
            ]);
    }
}
