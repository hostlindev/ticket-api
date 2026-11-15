<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Flujo vigente: open -> in_review -> resolved. Se migran los valores heredados.
     */
    public function up(): void
    {
        DB::table('tickets')->where('status', 'in_progress')->update(['status' => 'in_review']);
        DB::table('tickets')->where('status', 'closed')->update(['status' => 'resolved']);
    }

    public function down(): void
    {
        // Irreversible: los estados heredados ya no se distinguen.
    }
};
