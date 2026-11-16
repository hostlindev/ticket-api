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
        Schema::table('tickets', function (Blueprint $table): void {
            $table->string('reporter_external_id')->nullable()->after('parent_ticket_id');
            $table->string('reporter_name')->default('')->after('reporter_external_id');
            $table->string('reporter_email')->default('')->index()->after('reporter_name');
        });

        if (Schema::hasTable('reporters') && Schema::hasColumn('tickets', 'reporter_id')) {
            DB::table('tickets')
                ->select(['id', 'reporter_id'])
                ->orderBy('id')
                ->chunkById(200, function ($tickets): void {
                    $reporterIds = $tickets->pluck('reporter_id')->filter()->unique()->all();
                    $reporters = DB::table('reporters')
                        ->whereIn('id', $reporterIds)
                        ->get()
                        ->keyBy('id');

                    foreach ($tickets as $ticket) {
                        $reporter = $reporters->get($ticket->reporter_id);

                        DB::table('tickets')
                            ->where('id', $ticket->id)
                            ->update([
                                'reporter_external_id' => $ticket->reporter_id,
                                'reporter_name' => $reporter->name ?? 'Unknown',
                                'reporter_email' => $reporter->email ?? 'unknown@ticket.local',
                            ]);
                    }
                });
        }

        if (Schema::hasColumn('tickets', 'reporter_id')) {
            Schema::table('tickets', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('reporter_id');
            });
        }

        Schema::dropIfExists('reporters');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('reporters')) {
            Schema::create('reporters', function (Blueprint $table): void {
                $table->string('id')->primary();
                $table->string('name');
                $table->string('email')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('tickets', 'reporter_id')) {
            Schema::table('tickets', function (Blueprint $table): void {
                $table->string('reporter_id')->nullable()->after('system_id');
            });
        }

        DB::table('tickets')
            ->select(['id', 'reporter_external_id', 'reporter_name', 'reporter_email'])
            ->orderBy('id')
            ->chunkById(200, function ($tickets): void {
                foreach ($tickets as $ticket) {
                    $reporterId = $ticket->reporter_external_id ?: "usr_restored_{$ticket->id}";

                    DB::table('reporters')->updateOrInsert(
                        ['id' => $reporterId],
                        [
                            'name' => $ticket->reporter_name ?: 'Unknown',
                            'email' => $ticket->reporter_email ?: 'unknown@ticket.local',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    );

                    DB::table('tickets')
                        ->where('id', $ticket->id)
                        ->update(['reporter_id' => $reporterId]);
                }
            });

        Schema::table('tickets', function (Blueprint $table): void {
            $table->foreign('reporter_id')->references('id')->on('reporters')->cascadeOnDelete();
            $table->dropIndex(['reporter_email']);
            $table->dropColumn(['reporter_external_id', 'reporter_name', 'reporter_email']);
        });
    }
};
