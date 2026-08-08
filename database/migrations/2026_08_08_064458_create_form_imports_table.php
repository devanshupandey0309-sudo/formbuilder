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
        Schema::create('form_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type');
            $table->string('original_filename');
            $table->string('file_path');
            $table->string('status')->default('pending');
            $table->json('detected_structure')->nullable();
            $table->json('field_candidates')->nullable();
            $table->json('ambiguities')->nullable();
            $table->json('mapping')->nullable();
            $table->json('preview_data')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('ai_job_id')->nullable()->constrained('ai_jobs')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('form_imports');
    }
};
