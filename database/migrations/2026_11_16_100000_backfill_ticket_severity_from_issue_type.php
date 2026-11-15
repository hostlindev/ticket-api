<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Every ticket now gets a priority from its issue type ("other" counts as low priority),
     * so tickets that were left unclassified are backfilled.
     */
    public function up(): void
    {
        $map = [
            'blocks_flow' => ['P1', 60],
            'not_working' => ['P2', 240],
            'wrong_info' => ['P3', 1440],
            'slow' => ['P3', 1440],
            'visual' => ['P4', 4320],
            'other' => ['P4', 4320],
        ];

        foreach ($map as $issueType => [$severity, $slaMinutes]) {
            DB::table('tickets')
                ->whereNull('severity')
                ->where('issue_type', $issueType)
                ->orderBy('id')
                ->each(function (object $ticket) use ($severity, $slaMinutes): void {
                    DB::table('tickets')->where('id', $ticket->id)->update([
                        'severity' => $severity,
                        'sla_due_at' => $ticket->sla_due_at
                            ?? Carbon::parse($ticket->created_at)->addMinutes($slaMinutes),
                    ]);
                });
        }
    }

    public function down(): void
    {
        // Irreversible data backfill.
    }
};
