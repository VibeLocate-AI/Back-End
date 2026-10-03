<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE agencies
            MODIFY status ENUM(
                'active',
                'suspended',
                'pending',
                'rejected'
            )
            NOT NULL DEFAULT 'pending'
        ");
    }

    public function down(): void
    {
        DB::table('agencies')
            ->where('status', 'rejected')
            ->update([
                'status' => 'pending',
            ]);

        DB::statement("
            ALTER TABLE agencies
            MODIFY status ENUM(
                'active',
                'suspended',
                'pending'
            )
            NOT NULL DEFAULT 'pending'
        ");
    }
};