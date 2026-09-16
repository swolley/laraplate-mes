<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Helpers\MigrateUtils;
use Modules\MES\Enums\MESTables;

return new class extends Migration
{
    public function up(): void
    {
        $table_name = MESTables::LotLineages->value;
        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('parent_lot_id')
                ->constrained(MESTables::LotNumbers->value, 'id', "{$table_name}_parent_lot_id_FK")
                ->cascadeOnDelete();
            $table->foreignId('child_lot_id')
                ->constrained(MESTables::LotNumbers->value, 'id', "{$table_name}_child_lot_id_FK")
                ->cascadeOnDelete();
            MigrateUtils::prefixIndex($table, 'child_lot_id');
            $table->foreignId('production_order_id')
                ->nullable()
                ->constrained(MESTables::ProductionOrders->value, 'id', "{$table_name}_production_order_id_FK")
                ->nullOnDelete();
            MigrateUtils::prefixIndex($table, 'production_order_id');
            $table->decimal('quantity', 15, 4)->nullable();
            $table->timestamps();

            $table->unique(['parent_lot_id', 'child_lot_id'], "{$table_name}_parent_child_UNIQUE");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MESTables::LotLineages->value);
    }
};
