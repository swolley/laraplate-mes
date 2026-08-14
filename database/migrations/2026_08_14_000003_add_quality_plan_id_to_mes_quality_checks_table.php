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
        $table_name = MESTables::QualityChecks->value;
        Schema::table($table_name, function (Blueprint $table) use ($table_name): void {
            $table->foreignId('quality_plan_id')
                ->nullable()
                ->after('production_order_operation_id')
                ->constrained(MESTables::QualityPlans->value, 'id', "{$table_name}_quality_plan_id_FK")
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        $table_name = MESTables::QualityChecks->value;
        Schema::table($table_name, function (Blueprint $table) use ($table_name): void {
            $table->dropForeign("{$table_name}_quality_plan_id_FK");
            $table->dropColumn('quality_plan_id');
        });
    }
};
