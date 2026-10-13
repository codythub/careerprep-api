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
        Schema::table('resumes', function (Blueprint $table) {
            $table->string('extraction_status', 30)->default('pending');
            $table->longText('extracted_text')->nullable();
            $table->text('extraction_error')->nullable();
            $table->timestamp('extracted_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resumes', function (Blueprint $table) {
            $table->dropColumn([
                'extraction_status',
                'extracted_text',
                'extraction_error',
                'extracted_at',
            ]);
        });
    }
};
