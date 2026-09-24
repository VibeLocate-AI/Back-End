<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('api_logs', 'outcome')) {
                $table->string('outcome', 50)->nullable()->after('execution_time_ms');
            }

            if (!Schema::hasColumn('api_logs', 'ai_model')) {
                $table->string('ai_model', 120)->nullable()->after('outcome');
            }

            if (!Schema::hasColumn('api_logs', 'fallback_triggered')) {
                $table->boolean('fallback_triggered')
                    ->default(false)
                    ->after('ai_model');
            }

            if (!Schema::hasColumn('api_logs', 'error_message')) {
                $table->text('error_message')
                    ->nullable()
                    ->after('fallback_triggered');
            }
        });
    }

    public function down(): void
    {
        Schema::table('api_logs', function (Blueprint $table) {
            $columns = [];

            foreach ([
                'outcome',
                'ai_model',
                'fallback_triggered',
                'error_message',
            ] as $column) {
                if (Schema::hasColumn('api_logs', $column)) {
                    $columns[] = $column;
                }
            }

            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
