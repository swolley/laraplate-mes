<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\ERP\Enums\ERPTables;
use Modules\MES\Enums\MESTables;

return new class extends Migration
{
    public function up(): void
    {
        $table_name = MESTables::MaterialConsumptions->value;
        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('production_order_id')
                ->constrained(MESTables::ProductionOrders->value, 'id', "{$table_name}_production_order_id_FK")
                ->cascadeOnDelete();
            $table->foreignId('production_order_operation_id')
                ->nullable()
                ->constrained(MESTables::ProductionOrderOperations->value, 'id', "{$table_name}_operation_id_FK")
                ->nullOnDelete();
            $table->foreignId('item_id')
                ->constrained(ERPTables::Items->value, 'id', "{$table_name}_item_id_FK")
                ->restrictOnDelete();
            $table->foreignId('warehouse_id')
                ->constrained(ERPTables::Warehouses->value, 'id', "{$table_name}_warehouse_id_FK")
                ->restrictOnDelete();
            $table->decimal('quantity_planned', 15, 4);
            $table->decimal('quantity_consumed', 15, 4);
            $table->decimal('variance', 15, 4)->default(0);
            $table->string('uom', 16);
            $table->boolean('is_backflush')->default(true);
            $table->boolean('stock_shortage')->default(false);
            $table->dateTime('recorded_at');
            $table->timestamps();

            $table->unique(
                ['production_order_operation_id', 'item_id'],
                "{$table_name}_operation_item_UNIQUE",
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MESTables::MaterialConsumptions->value);
    }
};
