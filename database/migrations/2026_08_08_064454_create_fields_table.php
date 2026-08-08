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
        Schema::create('fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('label');
            $table->string('type');
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('config')->nullable();
            $table->json('validation')->nullable();
            $table->boolean('is_required')->default(false);
            $table->timestamps();

            $table->unique(['form_id', 'key']);
            $table->index(['section_id', 'sort_order']);
            $table->index(['form_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fields');
    }
};
