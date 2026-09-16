<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Helpers\MigrateUtils;
use Modules\ERP\Enums\ERPTables;
use Modules\MES\Enums\MESTables;
use Modules\MES\Enums\QualityCheckStatus;

return new class extends Migration
{
    public function up(): void
    {
        $table_name = MESTables::QualityChecks->value;
        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('company_id')
                ->constrained(ERPTables::Companies->value, 'id', "{$table_name}_company_id_FK")
                ->cascadeOnDelete();
            MigrateUtils::prefixIndex($table, 'company_id');
            $table->foreignId('production_order_id')
                ->constrained(MESTables::ProductionOrders->value, 'id', "{$table_name}_production_order_id_FK")
                ->cascadeOnDelete();
            MigrateUtils::prefixIndex($table, 'production_order_id');
            $table->foreignId('production_order_operation_id')
                ->nullable()
                ->constrained(MESTables::ProductionOrderOperations->value, 'id', "{$table_name}_operation_id_FK")
                ->nullOnDelete();
            MigrateUtils::prefixIndex($table, 'production_order_operation_id');
            $table->foreignId('quality_plan_id')
                ->nullable()
                ->constrained(MESTables::QualityPlans->value, 'id', "{$table_name}_quality_plan_id_FK")
                ->nullOnDelete();
            MigrateUtils::prefixIndex($table, 'quality_plan_id');
            $table->foreignId('item_id')
                ->constrained(ERPTables::Items->value, 'id', "{$table_name}_item_id_FK")
                ->restrictOnDelete();
            MigrateUtils::prefixIndex($table, 'item_id');
            $table->string('name', 255);
            $table->enum('status', QualityCheckStatus::values())
                ->default(QualityCheckStatus::Pending->value)
                ->index("{$table_name}_status_IDX");
            $table->text('notes')->nullable();
            $table->dateTime('checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MESTables::QualityChecks->value);
    }
};
