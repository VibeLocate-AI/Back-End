<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->enum('moderation_status', [
                'pending',
                'approved',
                'rejected',
            ])->default('pending')->after('status_id');

            $table->text('rejection_reason')
                ->nullable()
                ->after('moderation_status');

            $table->unsignedBigInteger('reviewed_by')
                ->nullable()
                ->after('rejection_reason');

            $table->timestamp('reviewed_at')
                ->nullable()
                ->after('reviewed_by');

            $table->index('moderation_status');
            $table->index('reviewed_by');
        });

        // العقارات الحالية مستوردة وموجودة مسبقًا، نعتبرها معتمدة.
        DB::table('properties')->update([
            'moderation_status' => 'approved',
        ]);
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex(['moderation_status']);
            $table->dropIndex(['reviewed_by']);

            $table->dropColumn([
                'moderation_status',
                'rejection_reason',
                'reviewed_by',
                'reviewed_at',
            ]);
        });
    }
};