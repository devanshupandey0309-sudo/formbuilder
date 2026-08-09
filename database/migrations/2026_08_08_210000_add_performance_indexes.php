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
        Schema::table('submission_answers', function (Blueprint $table) {
            $table->index('field_key', 'submission_answers_field_key_index');
        });

        Schema::table('form_imports', function (Blueprint $table) {
            $table->index(['form_id', 'status'], 'form_imports_form_id_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('submission_answers', function (Blueprint $table) {
            $table->dropIndex('submission_answers_field_key_index');
        });

        Schema::table('form_imports', function (Blueprint $table) {
            $table->dropIndex('form_imports_form_id_status_index');
        });
    }
};
