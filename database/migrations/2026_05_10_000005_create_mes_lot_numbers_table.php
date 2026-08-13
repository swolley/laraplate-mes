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
        $table_name = MESTables::LotNumbers->value;
        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('company_id')
                ->constrained(ERPTables::Companies->value, 'id', "{$table_name}_company_id_FK")
                ->cascadeOnDelete();
            $table->foreignId('item_id')
                ->constrained(ERPTables::Items->value, 'id', "{$table_name}_item_id_FK")
                ->restrictOnDelete();
            $table->foreignId('production_order_id')
                ->nullable()
                ->constrained(MESTables::ProductionOrders->value, 'id', "{$table_name}_production_order_id_FK")
                ->nullOnDelete();
            $table->foreignId('warehouse_id')
                ->nullable()
                ->constrained(ERPTables::Warehouses->value, 'id', "{$table_name}_warehouse_id_FK")
                ->nullOnDelete();
            $table->string('code', 64);
            $table->decimal('quantity', 15, 4)->default(0);
            $table->dateTime('produced_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code'], "{$table_name}_company_code_UNIQUE");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MESTables::LotNumbers->value);
    }
};
