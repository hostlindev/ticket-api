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
        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('system_id');
            $table->foreign('system_id')->references('id')->on('systems')->cascadeOnDelete();
            $table->string('reporter_id');
            $table->foreign('reporter_id')->references('id')->on('reporters')->cascadeOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('description');
            $table->string('issue_type');
            $table->string('severity')->nullable();
            $table->string('status')->default('open');
            $table->string('screenshot_path')->nullable();
            $table->string('browser');
            $table->string('ip_address', 45);
            $table->timestamp('sla_due_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'severity']);
            $table->index(['system_id', 'created_at']);
            $table->index('issue_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
