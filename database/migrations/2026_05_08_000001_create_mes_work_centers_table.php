<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\ERP\Enums\ERPTables;
use Modules\MES\Enums\MESTables;

return new class extends Migration
{
    public function up(): void
    {
        $table_name = MESTables::WorkCenters->value;
        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('company_id')->constrained(ERPTables::Companies->value, 'id', "{$table_name}_company_id_FK")->restricadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->enum('type', ['machine', 'cell', 'line', 'manual_station']);
            $table->decimal('capacity_per_hour', 10, 4);
            $table->string('capacity_uom', 16);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code'], "{$table_name}_company_code_UN");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MESTables::WorkCenters->value);
    }
};
