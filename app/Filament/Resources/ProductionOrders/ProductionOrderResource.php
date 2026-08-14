<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\ProductionOrders;

use BackedEnum;
use Coolsam\Modules\Resource;
use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\MES\Filament\Resources\ProductionOrders\Pages\EditProductionOrder;
use Modules\MES\Filament\Resources\ProductionOrders\Pages\ListProductionOrders;
use Modules\MES\Filament\Resources\ProductionOrders\Schemas\ProductionOrderForm;
use Modules\MES\Filament\Resources\ProductionOrders\Tables\ProductionOrdersTable;
use Modules\MES\Models\ProductionOrder;
use Override;
use UnitEnum;

/**
 * Production orders are created and advanced through ProductionOrderService
 * (number allocation, immutable snapshots, state transitions), so this resource
 * intentionally offers list and edit only — creation goes through the service /
 * domain actions, not a bare Filament form.
 */
final class ProductionOrderResource extends Resource
{
    #[Override]
    protected static ?string $model = ProductionOrder::class;

    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    #[Override]
    protected static string|UnitEnum|null $navigationGroup = 'MES';

    #[Override]
    protected static ?int $navigationSort = 40;

    #[Override]
    protected static ?string $recordTitleAttribute = 'number';

    public static function getSlug(?Panel $panel = null): string
    {
        return 'mes/production-orders';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return ProductionOrderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductionOrdersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductionOrders::route('/'),
            'edit' => EditProductionOrder::route('/{record}/edit'),
        ];
    }
}
