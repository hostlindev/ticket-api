<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->softDeletes();
        });

        Schema::table('tickets', function (Blueprint $table): void {
            // No FK cascade: users are only soft-deleted, so the resolver is always kept.
            $table->foreignId('resolved_by_id')->nullable()->after('assignee_id')->constrained('users');
        });

        // Best-effort backfill: previously resolved tickets were resolved by their assignee.
        DB::table('tickets')
            ->whereIn('status', ['resolved', 'closed'])
            ->whereNull('resolved_by_id')
            ->whereNotNull('assignee_id')
            ->update(['resolved_by_id' => DB::raw('assignee_id')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('resolved_by_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
