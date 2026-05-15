<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\ERP\Enums\ERPTables;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(ERPTables::Items->value, function (Blueprint $table): void {
            $table->enum('tracing_type', ['none', 'lot', 'serial'])->default('none')->after('uom');
        });
    }

    public function down(): void
    {
        Schema::table(ERPTables::Items->value, function (Blueprint $table): void {
            $table->dropColumn('tracing_type');
        });
    }
};
