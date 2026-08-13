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
        $table_name = MESTables::ShiftInstances->value;
        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('shift_id')
                ->constrained(MESTables::Shifts->value, 'id', "{$table_name}_shift_id_FK")
                ->cascadeOnDelete();
            $table->foreignId('work_center_id')
                ->nullable()
                ->constrained(MESTables::WorkCenters->value, 'id', "{$table_name}_work_center_id_FK")
                ->nullOnDelete();
            $table->date('date');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->timestamps();

            $table->unique(['shift_id', 'work_center_id', 'date'], "{$table_name}_shift_wc_date_UNIQUE");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MESTables::ShiftInstances->value);
    }
};
