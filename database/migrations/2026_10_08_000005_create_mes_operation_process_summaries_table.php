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
        $table_name = MESTables::OperationProcessSummaries->value;
        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('company_id')->constrained(ERPTables::Companies->value, 'id', "{$table_name}_company_id_FK")->restrictOnDelete();
            $table->foreignId('production_order_operation_id')
                ->constrained(MESTables::ProductionOrderOperations->value, 'id', "{$table_name}_operation_id_FK")
                ->cascadeOnDelete();
            $table->foreignId('signal_id')
                ->constrained(MESTables::MachineSignals->value, 'id', "{$table_name}_signal_id_FK")
                ->cascadeOnDelete();
            $table->decimal('min', 18, 6);
            $table->decimal('max', 18, 6);
            $table->decimal('avg', 18, 6);
            $table->unsignedInteger('count');
            $table->unsignedInteger('out_of_range_count')->default(0);
            $table->dateTime('first_ts', 3);
            $table->dateTime('last_ts', 3);
            MigrateUtils::timestamps($table, hasCreateUpdate: true);

            $table->unique(['production_order_operation_id', 'signal_id'], "{$table_name}_operation_signal_UN");
            MigrateUtils::prefixIndex($table, 'company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MESTables::OperationProcessSummaries->value);
    }
};
