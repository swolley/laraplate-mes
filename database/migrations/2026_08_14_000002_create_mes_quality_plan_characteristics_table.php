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
        $table_name = MESTables::QualityPlanCharacteristics->value;
        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('quality_plan_id')
                ->constrained(MESTables::QualityPlans->value, 'id', "{$table_name}_quality_plan_id_FK")
                ->cascadeOnDelete();
            $table->string('characteristic', 255);
            $table->decimal('nominal', 15, 4)->nullable();
            $table->decimal('lower_limit', 15, 4)->nullable();
            $table->decimal('upper_limit', 15, 4)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MESTables::QualityPlanCharacteristics->value);
    }
};
