<?php

namespace App\Filament\Resources\Debtors\Schemas;

use Filament\Schemas\Schema;
use Filament\Infolists\Components\TextEntry;

class DebtorInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
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
