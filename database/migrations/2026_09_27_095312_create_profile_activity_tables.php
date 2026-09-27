<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Property Views
        |--------------------------------------------------------------------------
        */

        if (!Schema::hasTable('property_views')) {
            Schema::create('property_views', function (Blueprint $table) {
                $table->id();

                $table->unsignedBigInteger('property_id');
                $table->unsignedBigInteger('user_id')->nullable();

                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();

                $table->timestamp('viewed_at')->useCurrent();

                $table->index([
                    'user_id',
                    'viewed_at'
                ]);

                $table->foreign('property_id')
                    ->references('id')
                    ->on('properties')
                    ->cascadeOnDelete();

                $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Saved Searches
        |--------------------------------------------------------------------------
        */

        if (!Schema::hasTable('saved_searches')) {
            Schema::create('saved_searches', function (Blueprint $table) {
                $table->id();

                $table->unsignedBigInteger('user_id');

                $table->string('search_name', 100);

                $table->json('query_parameters');

                $table->boolean('is_active')
                    ->default(true);

                $table->timestamps();

                $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->cascadeOnDelete();

                $table->index([
                    'user_id',
                    'is_active'
                ]);
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Property Leads / Inquiries
        |--------------------------------------------------------------------------
        */

        if (!Schema::hasTable('property_leads')) {
            Schema::create('property_leads', function (Blueprint $table) {
                $table->id();

                $table->unsignedBigInteger('property_id');

                $table->unsignedBigInteger('user_id')
                    ->nullable();

                $table->string('full_name', 150);

                $table->string('email', 150);

                $table->string('phone', 30)
                    ->nullable();

                $table->text('message')
                    ->nullable();

                $table->enum('status', [
                    'new',
                    'contacted',
                    'converted',
                    'closed',
                ])->default('new');

                $table->timestamp('created_at')
                    ->useCurrent();

                $table->foreign('property_id')
                    ->references('id')
                    ->on('properties')
                    ->cascadeOnDelete();

                $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();

                $table->index([
                    'user_id',
                    'created_at'
                ]);
            });
        }

        /*
        |--------------------------------------------------------------------------
        | User Preferences
        |--------------------------------------------------------------------------
        */

        if (!Schema::hasTable('user_preferences')) {
            Schema::create('user_preferences', function (Blueprint $table) {
                $table->id();

                $table->unsignedBigInteger('user_id')
                    ->unique();

                $table->string('preferred_area', 150)
                    ->nullable();

                $table->json('property_types')
                    ->nullable();

                $table->decimal(
                    'min_price',
                    15,
                    2
                )->nullable();

                $table->decimal(
                    'max_price',
                    15,
                    2
                )->nullable();

                $table->unsignedInteger(
                    'min_bedrooms'
                )->nullable();

                $table->unsignedInteger(
                    'max_bedrooms'
                )->nullable();

                $table->json('lifestyle_preferences')
                    ->nullable();

                $table->boolean(
                    'email_notifications'
                )->default(true);

                $table->boolean(
                    'browser_notifications'
                )->default(true);

                $table->timestamps();

                $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
        Schema::dropIfExists('property_leads');
        Schema::dropIfExists('saved_searches');
        Schema::dropIfExists('property_views');
    }
};