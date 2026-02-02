<?php

namespace App\Filament\Resources\Debtors\Pages;

use App\Models\Debtor;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Widgets\DebtorStatsOverview;
use App\Filament\Resources\Debtors\DebtorResource;

class ListDebtors extends ListRecords
{
    protected static string $resource = DebtorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Qarzdor yaratish'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            DebtorStatsOverview::class,
        ];
    }

    public function getTabs(): array
    {
        return [
            'Qarzdorlar' => Tab::make(__('Qarzdorlar'))->badge(Debtor::scopes('stillInDebt')->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->scopes('stillInDebt')),
            'Qarzdorlik yopilganlar' => Tab::make(__('Qarzdorlik yopilganlar'))->badge(Debtor::scopes('zeroDebt')->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->scopes('zeroDebt')),
            'Muddati o`tgan qarzdorlar' => Tab::make(__('Muddati o`tgan qarzdorlar'))->badge(Debtor::scopes('overdue')->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->scopes('overdue')),
        ];
    }
}
