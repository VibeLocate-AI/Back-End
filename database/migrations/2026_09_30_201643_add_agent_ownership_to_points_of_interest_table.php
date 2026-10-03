<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('points_of_interest', function (Blueprint $table) {
            $table->unsignedBigInteger('agency_id')
                ->nullable()
                ->after('id');

            $table->unsignedBigInteger('created_by')
                ->nullable()
                ->after('agency_id');

            $table->index(
                'agency_id',
                'idx_poi_agency_id'
            );

            $table->index(
                'created_by',
                'idx_poi_created_by'
            );

            $table->foreign('agency_id')
                ->references('id')
                ->on('agencies')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('points_of_interest', function (Blueprint $table) {
            $table->dropForeign([
                'agency_id'
            ]);

            $table->dropForeign([
                'created_by'
            ]);

            $table->dropIndex(
                'idx_poi_agency_id'
            );

            $table->dropIndex(
                'idx_poi_created_by'
            );

            $table->dropColumn([
                'agency_id',
                'created_by',
            ]);
        });
    }
};