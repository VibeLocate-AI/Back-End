<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();

            // المستخدم صاحب الإشعار
            $table->unsignedBigInteger('user_id');

            // property | offer | event | review | system
            $table->enum('type', [
                'property',
                'offer',
                'event',
                'review',
                'system',
            ]);

            // محتوى الإشعار
            $table->string('title');
            $table->text('message');

            // صورة اختيارية
            $table->text('image')->nullable();

            // العنصر المرتبط بالإشعار
            // مثال: property_id أو offer_id أو event_id
            $table->unsignedBigInteger('reference_id')->nullable();

            // property | offer | event | review
            $table->string('reference_type', 50)->nullable();

            // رابط اختياري للتنقل من الإشعار
            $table->text('action_url')->nullable();

            // حالة القراءة
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            // ربط الإشعار بالمستخدم
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            // لتحسين جلب إشعارات المستخدم
            $table->index(['user_id', 'is_read']);
            $table->index(['type']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};