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
        $table_name = MESTables::ProductionOrders->value;
        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('company_id')
                ->constrained(ERPTables::Companies->value, 'id', "{$table_name}_company_id_FK")
                ->cascadeOnDelete();
            $table->string('number', 32);
            $table->foreignId('item_id')
                ->constrained(ERPTables::Items->value, 'id', "{$table_name}_item_id_FK")
                ->restrictOnDelete();
            $table->decimal('quantity_planned', 15, 4);
            $table->decimal('quantity_produced', 15, 4)->nullable();
            $table->decimal('quantity_scrapped', 15, 4)->nullable();
            $table->string('uom', 16);
            $table->enum('status', ['draft', 'released', 'in_progress', 'completed', 'cancelled'])
                ->default('draft')
                ->index("{$table_name}_status_IDX");
            $table->dateTime('planned_start_at');
            $table->dateTime('planned_end_at');
            $table->dateTime('actual_start_at')->nullable();
            $table->dateTime('actual_end_at')->nullable();
            $table->foreignId('warehouse_id')
                ->constrained(ERPTables::Warehouses->value, 'id', "{$table_name}_warehouse_id_FK")
                ->restrictOnDelete();
            $table->foreignId('sales_order_id')
                ->nullable()
                ->constrained(ERPTables::SalesOrders->value, 'id', "{$table_name}_sales_order_id_FK")
                ->nullOnDelete();
            $table->json('bom_snapshot');
            $table->json('routing_snapshot');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'number'], "{$table_name}_company_number_UNIQUE");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MESTables::ProductionOrders->value);
    }
};
