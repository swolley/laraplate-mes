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
        $table_name = MESTables::MachineIncidents->value;
        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('company_id')->constrained(ERPTables::Companies->value, 'id', "{$table_name}_company_id_FK")->restrictOnDelete();
            $table->foreignId('source_id')
                ->constrained(MESTables::MachineSources->value, 'id', "{$table_name}_source_id_FK")
                ->cascadeOnDelete();
            $table->unsignedBigInteger('device_id')->nullable();
            $table->foreign('device_id', "{$table_name}_device_id_FK")
                ->references('id')->on(MESTables::MachineDevices->value)
                ->nullOnDelete();
            $table->string('type', 32);
            $table->json('detail');
            $table->dateTime('occurred_at');
            $table->dateTime('resolved_at')->nullable();

            MigrateUtils::timestamps($table, hasCreateUpdate: true);

            $table->index(['source_id', 'type', 'resolved_at'], "{$table_name}_source_type_resolved_IDX");
            MigrateUtils::prefixIndex($table, 'company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MESTables::MachineIncidents->value);
    }
};
