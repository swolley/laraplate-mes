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
        $table_name = MESTables::QualityCheckMeasurements->value;
        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('quality_check_id')
                ->constrained(MESTables::QualityChecks->value, 'id', "{$table_name}_quality_check_id_FK")
                ->cascadeOnDelete();
            MigrateUtils::prefixIndex($table, 'quality_check_id');
            $table->string('characteristic', 255);
            $table->decimal('nominal', 15, 4)->nullable();
            $table->decimal('lower_limit', 15, 4)->nullable();
            $table->decimal('upper_limit', 15, 4)->nullable();
            $table->decimal('measured_value', 15, 4);
            $table->boolean('is_within_limits')->default(true);
            $table->unsignedBigInteger('quality_plan_characteristic_id')->nullable();
            $table->string('serial', 128)->nullable();
            $table->dateTime('measured_at', 3)->nullable();
            $table->enum('source', ['manual', 'machine'])->default('manual');
            $table->unsignedBigInteger('machine_signal_id')->nullable();
            $table->timestamps();

            // Not unique: a nullable composite unique breaks manual rows on some drivers (see the probe measurements plan, R3).
            $table->index(['machine_signal_id', 'measured_at'], "{$table_name}_signal_measured_IDX");
            $table->index('quality_plan_characteristic_id', "{$table_name}_characteristic_IDX");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MESTables::QualityCheckMeasurements->value);
    }
};
