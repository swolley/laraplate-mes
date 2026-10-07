<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Helpers\MigrateUtils;
use Modules\ERP\Enums\ERPTables;
use Modules\MES\Enums\MachineState;
use Modules\MES\Enums\MESTables;

return new class extends Migration
{
    public function up(): void
    {
        $table_name = MESTables::MachineStateIntervals->value;
        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('company_id')->constrained(ERPTables::Companies->value, 'id', "{$table_name}_company_id_FK")->restrictOnDelete();
            $table->foreignId('device_id')
                ->constrained(MESTables::MachineDevices->value, 'id', "{$table_name}_device_id_FK")
                ->cascadeOnDelete();
            $table->foreignId('work_center_id')
                ->constrained(MESTables::WorkCenters->value, 'id', "{$table_name}_work_center_id_FK")
                ->cascadeOnDelete();
            $table->enum('state', MachineState::values());
            $table->string('alarm_code', 64)->nullable();
            $table->dateTime('started_at', 3);
            $table->dateTime('ended_at', 3)->nullable();
            MigrateUtils::timestamps($table, hasCreateUpdate: true);

            $table->unique(['device_id', 'started_at'], "{$table_name}_device_started_UN");
            $table->index(['work_center_id', 'started_at'], "{$table_name}_work_center_started_IDX");
            MigrateUtils::prefixIndex($table, 'company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MESTables::MachineStateIntervals->value);
    }
};
