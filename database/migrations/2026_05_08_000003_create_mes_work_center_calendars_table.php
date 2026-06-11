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
        $table_name = MESTables::WorkCenterCalendars->value;
        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('work_center_id')->constrained(MESTables::WorkCenters->value, 'id', "{$table_name}_work_center_id_FK")->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week'); // 0=Monday … 6=Sunday
            $table->time('start_time');
            $table->time('end_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MESTables::WorkCenterCalendars->value);
    }
};
