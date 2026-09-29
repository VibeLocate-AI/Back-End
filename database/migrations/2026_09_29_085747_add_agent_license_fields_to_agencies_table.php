<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->string('license_authority', 150)
                ->nullable()
                ->after('license_number');

            $table->date('license_expiry_date')
                ->nullable()
                ->after('license_authority');

            $table->string('license_document_path', 500)
                ->nullable()
                ->after('license_expiry_date');

            $table->string('company_document_path', 500)
                ->nullable()
                ->after('license_document_path');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn([
                'license_authority',
                'license_expiry_date',
                'license_document_path',
                'company_document_path',
            ]);
        });
    }
};