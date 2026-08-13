<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
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
            $table->string('characteristic', 255);
            $table->decimal('nominal', 15, 4)->nullable();
            $table->decimal('lower_limit', 15, 4)->nullable();
            $table->decimal('upper_limit', 15, 4)->nullable();
            $table->decimal('measured_value', 15, 4);
            $table->boolean('is_within_limits')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MESTables::QualityCheckMeasurements->value);
    }
};
