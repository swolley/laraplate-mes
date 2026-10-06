<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Helpers\MigrateUtils;
use Modules\ERP\Enums\ERPTables;
use Modules\MES\Enums\MESTables;
use Modules\MES\Enums\MachineTransport;

return new class extends Migration
{
    public function up(): void
    {
        $table_name = MESTables::MachineSources->value;
        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('company_id')->constrained(ERPTables::Companies->value, 'id', "{$table_name}_company_id_FK")->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->string('normalizer', 64)->default('canonical');
            $table->enum('transport', MachineTransport::values())->default(MachineTransport::Http->value);
            $table->string('mqtt_topic', 255)->nullable();
            $table->json('normalizer_options')->nullable();
            $table->string('protocol_version', 16)->default('1');
            $table->unsignedInteger('heartbeat_timeout_seconds')->default(120);
            $table->dateTime('last_seen_at')->nullable();
            $table->unsignedBigInteger('last_seq')->nullable();
            $table->boolean('is_active')->default(true);

            MigrateUtils::timestamps($table, hasCreateUpdate: true, hasSoftDelete: true);

            $table->unique(['company_id', 'code'], "{$table_name}_company_code_UN");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MESTables::MachineSources->value);
    }
};
