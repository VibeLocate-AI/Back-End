<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('reporter_id');
            $table->unsignedBigInteger('reported_user_id')->nullable();
            $table->unsignedBigInteger('property_id')->nullable();

            $table->enum('type', [
                'user',
                'property',
                'fraud',
                'inappropriate_content',
                'other'
            ])->default('other');

            $table->string('subject', 255);
            $table->text('description');

            $table->enum('status', [
                'pending',
                'reviewing',
                'resolved',
                'rejected'
            ])->default('pending');

            $table->text('admin_notes')->nullable();

            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->index('reporter_id');
            $table->index('reported_user_id');
            $table->index('property_id');
            $table->index('type');
            $table->index('status');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};