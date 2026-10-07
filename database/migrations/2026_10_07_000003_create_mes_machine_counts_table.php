<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Helpers\MigrateUtils;
use Modules\ERP\Enums\ERPTables;
use Modules\MES\Enums\MESTables;

return new class extends Migration
{
    public function up(): void
    {
        $table_name = MESTables::MachineCounts->value;
        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('company_id')->constrained(ERPTables::Companies->value, 'id', "{$table_name}_company_id_FK")->restrictOnDelete();
            $table->foreignId('signal_id')
                ->constrained(MESTables::MachineSignals->value, 'id', "{$table_name}_signal_id_FK")
                ->cascadeOnDelete();
            $table->foreignId('device_id')
                ->constrained(MESTables::MachineDevices->value, 'id', "{$table_name}_device_id_FK")
                ->cascadeOnDelete();
            $table->foreignId('work_center_id')
                ->constrained(MESTables::WorkCenters->value, 'id', "{$table_name}_work_center_id_FK")
                ->cascadeOnDelete();
            $table->foreignId('production_order_operation_id')
                ->nullable()
                ->constrained(MESTables::ProductionOrderOperations->value, 'id', "{$table_name}_operation_id_FK")
                ->nullOnDelete();
            $table->dateTime('ts', 3);
            $table->decimal('good', 18, 4)->default(0);
            $table->decimal('scrap', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);
            $table->decimal('raw_value', 18, 4);
            MigrateUtils::timestamps($table, hasCreateUpdate: true);

            $table->unique(['signal_id', 'ts'], "{$table_name}_signal_ts_UN");
            $table->index(['work_center_id', 'ts'], "{$table_name}_work_center_ts_IDX");
            $table->index('production_order_operation_id', "{$table_name}_operation_IDX");
            MigrateUtils::prefixIndex($table, 'company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MESTables::MachineCounts->value);
    }
};
