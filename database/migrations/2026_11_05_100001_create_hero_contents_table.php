<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hero_contents', function (Blueprint $table) {
            $table->id();

            // Content
            $table->string('image');
            $table->string('title');
            $table->string('subtitle', 500)->nullable();
            $table->string('button_text', 60)->nullable();
            $table->string('button_path', 2048)->nullable();
            $table->string('bg_color', 7)->nullable();
            $table->string('bg_image')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hero_contents');
    }
};