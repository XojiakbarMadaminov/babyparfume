<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\Stock;
use App\Helpers\Helper;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        // Optimize: fetch active stocks once and reuse in all closures
        $stocks = self::getActiveStocks();

        return $schema
            ->components([
                Section::make('Tovar maʼlumotlari')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label('Nomi')
                            ->required()
                            ->columnSpanFull(),

                        TextInput::make('barcode')
                            ->label('Bar kod')
                            ->unique('products', 'barcode', ignoreRecord: true, modifyRuleUsing: function (\Illuminate\Validation\Rules\Unique $rule) {
                                $storeId = auth()->user()?->current_store_id;
                                if ($storeId) {
                                    $rule->where('store_id', $storeId);
                                }

                                return $rule;
                            })
                            ->numeric()
                            ->required()
                            ->autofocus()
                            ->suffixAction(
                                Action::make('generateBarcode')
                                    ->icon('heroicon-m-sparkles')
                                    ->tooltip('EAN-13 Bar kod yaratish')
                                    ->action(function (Set $set) {
                                        $set('barcode', Helper::generateEAN13Barcode());
                                    })
                            ),

                        //                        Select::make('category_id')
                        //                            ->label('Kategoriyasi')
                        //                            ->preload()
                        //                            ->relationship('category', 'name')
                        //                            ->searchable(),

                        Select::make('type')
                            ->label('Turi')
                            ->options([
                                'size'    => 'Razmerli',
                                'package' => 'Paketli',
                            ])
                            ->default('package')
                            ->required()
                            ->reactive(),
                    ])->columns(3),

                Section::make('Narxlar')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('initial_price')
                            ->label('Kelgan narxi')
                            ->numeric()
                            ->required(),

                        TextInput::make('price')
                            ->label('Sotish narxi')
                            ->numeric()
                            ->required(),
                    ])->columns(),

                Section::make('Razmerlar va Stocklar')
                    ->columnSpanFull()
                    ->description('Har bir razmer uchun har bir ombordagi miqdorni kiriting')
                    ->visible(fn (Get $get) => ($get('type') ?? 'size') === 'size')
                    ->schema([
                        Repeater::make('sizes')
                            ->label('Razmerlar')
                            ->schema(function () use ($stocks) {
                                return [
                                    Grid::make()
                                        ->columns(count($stocks) + 1)
                                        ->schema(function () use ($stocks) {
                                            $fields = [];

                                            $fields[] = TextInput::make('size')
                                                ->label('Razmer')
                                                ->numeric();

                                            foreach ($stocks as $id => $name) {
                                                $fields[] = TextInput::make("stock_{$id}")
                                                    ->label($name)
                                                    ->numeric()
                                                    ->default(0);
                                            }

                                            return $fields;
                                        }),
                                ];
                            })
                            ->default(function () {
                                return collect(range(36, 41))
                                    ->map(fn ($size) => ['size' => $size])
                                    ->toArray();
                            })
                            ->columns(1)
                            ->reorderable(false),

                    ]),

                Section::make('Paket miqdori')
                    ->columnSpanFull()
                    ->description('Har bir ombor uchun umumiy paket miqdorini kiriting')
                    ->visible(fn (Get $get) => ($get('type') ?? 'size') === 'package')
                    ->schema(function () use ($stocks) {
                        return [
                            Grid::make()
                                ->columns(count($stocks))
                                ->schema(function () use ($stocks) {
                                    $fields = [];
                                    foreach ($stocks as $id => $name) {
                                        $fields[] = TextInput::make("pkg_stock_{$id}")
                                            ->label($name)
                                            ->numeric()
                                            ->default(0);
                                    }

                                    return $fields;
                                }),
                        ];
                    }),
                //                Section::make('Rasm')
                //                    ->columnSpanFull()
                //                    ->schema(function () {
                //                        $upload = SpatieMediaLibraryFileUpload::make('images')
                //                            ->disk(config('filesystems.default'))
                //                            ->collection(Product::IMAGE_COLLECTION)
                //                            ->label('Mahsulot rasmlari')
                //                            ->imageEditor()
                //                            ->maxSize(10240)
                //                            ->multiple()
                //                            ->reorderable()
                //                            ->responsiveImages()
                //                            ->extraAttributes(['class' => 'cursor-zoom-in', 'capture' => 'environment'])
                //                            ->visibility('public');
                //
                //                        if (Product::canOptimizeImages()) {
                //                            $upload->conversion(Product::OPTIMIZED_CONVERSION); // WebP konversiyani ishlatadi
                //                        } else {
                //                            $upload->helperText('Diqqat: GD yoki Imagick PHP kengaytmasi yoqilmagan. Rasm optimizatsiyasi vaqtincha o\'chirildi.');
                //                        }
                //
                //                        return [$upload];
                //                    }),
            ]);
    }

    private static function getActiveStocks(): array
    {
        static $cached = null;
        if ($cached === null) {
            $cached = Stock::scopes('active')->pluck('name', 'id')->toArray();
        }

        return $cached;
    }
}
