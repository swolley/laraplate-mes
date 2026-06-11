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
        $table_name = MESTables::BomLines->value;
        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('bom_id')->constrained(MESTables::Boms->value, 'id', "{$table_name}_bom_id_FK")->cascadeOnDelete();
            $table->foreignId('item_id')->constrained(ERPTables::Items->value, 'id', "{$table_name}_item_id_FK")->cascadeOnDelete();
            $table->decimal('quantity', 15, 4);
            $table->string('uom', 16);
            $table->enum('consumption_method', ['backflush', 'manual']);
            $table->integer('sort_order')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MESTables::BomLines->value);
    }
};
