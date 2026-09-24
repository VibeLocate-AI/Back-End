<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vibe_reports', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('property_id')->unique();
            $table->unsignedBigInteger('neighborhood_id')->nullable();

            $table->enum('status', [
                'pending',
                'generated',
                'failed'
            ])->default('pending');

            $table->unsignedInteger('poi_count')->default(0);

            $table->json('report_data')->nullable();

            $table->timestamp('generated_at')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();

            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index('neighborhood_id');
            $table->index('status');
            $table->index(['neighborhood_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vibe_reports');
    }
};