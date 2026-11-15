<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('helpdesk_systems', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 50)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('helpdesk_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('system_id')->constrained('helpdesk_systems');
            $table->foreignId('parent_ticket_id')
                ->nullable()
                ->constrained('helpdesk_tickets')
                ->nullOnDelete();

            $table->string('error_type', 50);
            $table->text('description');

            $table->string('module_route');
            $table->ipAddress('client_ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->jsonb('reporter_data');
            $table->jsonb('telemetry')->nullable();

            $table->enum('layer', ['frontend', 'backend', 'integracion', 'por_definir'])->default('por_definir');
            $table->string('fingerprint', 64)->index();
            $table->enum('status', ['abierto', 'en_progreso', 'resuelto', 'cerrado', 'duplicado'])->default('abierto');
            $table->enum('priority', ['baja', 'media', 'alta', 'critica'])->default('media');
            $table->unsignedInteger('occurrences_count')->default(1);

            $table->timestamps();
        });

        Schema::create('helpdesk_attachments', function (Blueprint $table): void {
            $table->id();
            $table->morphs('attachable');
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime_type', 50);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('helpdesk_attachments');
        Schema::dropIfExists('helpdesk_tickets');
        Schema::dropIfExists('helpdesk_systems');
    }
};
