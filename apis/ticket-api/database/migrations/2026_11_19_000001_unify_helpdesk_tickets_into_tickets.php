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
        Schema::dropIfExists('helpdesk_attachments');
        Schema::dropIfExists('helpdesk_tickets');
        Schema::dropIfExists('helpdesk_systems');

        Schema::table('tickets', function (Blueprint $table): void {
            $table->foreignId('parent_ticket_id')
                ->nullable()
                ->after('system_id')
                ->constrained('tickets')
                ->nullOnDelete();

            $table->string('module_route')->nullable()->after('description');
            $table->text('user_agent')->nullable()->after('ip_address');
            $table->jsonb('reporter_data')->nullable()->after('user_agent');
            $table->jsonb('telemetry')->nullable()->after('reporter_data');

            $table->string('layer', 30)->default('por_definir')->after('telemetry');
            $table->string('fingerprint', 64)->nullable()->index()->after('layer');
            $table->unsignedInteger('occurrences_count')->default(1)->after('status');

            $table->index(['system_id', 'fingerprint', 'parent_ticket_id'], 'tickets_dedup_lookup_idx');
        });

        Schema::create('attachments', function (Blueprint $table): void {
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
        Schema::dropIfExists('attachments');

        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropIndex('tickets_dedup_lookup_idx');
            $table->dropIndex(['fingerprint']);
            $table->dropConstrainedForeignId('parent_ticket_id');
            $table->dropColumn([
                'module_route',
                'user_agent',
                'reporter_data',
                'telemetry',
                'layer',
                'fingerprint',
                'occurrences_count',
            ]);
        });
    }
};
