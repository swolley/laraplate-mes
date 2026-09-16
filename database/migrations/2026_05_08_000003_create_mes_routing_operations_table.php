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
        $table_name = MESTables::RoutingOperations->value;
        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('routing_id')
                ->constrained(MESTables::Routings->value, 'id', "{$table_name}_routing_id_FK")
                ->cascadeOnDelete();
            $table->foreignId('work_center_id')
                ->constrained(MESTables::WorkCenters->value, 'id', "{$table_name}_work_center_id_FK")
                ->restrictOnDelete();
            $table->integer('sequence');
            $table->string('description', 255);
            $table->integer('setup_time_minutes')->default(0);
            $table->decimal('cycle_time_minutes', 10, 4)->default(0);
            $table->boolean('is_parallel')->default(false);

            $table->unique(['routing_id', 'sequence'], "{$table_name}_routing_sequence_UNIQUE");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MESTables::RoutingOperations->value);
    }
};
