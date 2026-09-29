<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_communities', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('community_id');

            $table->primary([
                'user_id',
                'community_id',
            ]);

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            $table->foreign('community_id')
                ->references('id')
                ->on('communities')
                ->cascadeOnDelete();
        });

        Schema::create('agent_property_types', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('property_type_id');

            $table->primary([
                'user_id',
                'property_type_id',
            ]);

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            $table->foreign('property_type_id')
                ->references('id')
                ->on('property_types')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_property_types');
        Schema::dropIfExists('agent_communities');
    }
};