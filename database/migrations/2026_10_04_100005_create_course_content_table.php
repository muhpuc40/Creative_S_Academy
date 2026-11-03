<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /** What a class/exam/assignment item shows. Reusable by many items. */
    public function up(): void
    {
        Schema::create('course_content', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('thumbnail')->nullable();
            $table->string('url')->nullable();                           // an external link (or an old file on the public disk)
            // An uploaded file (chunkit, admin only) lives on a PRIVATE disk - never a public URL.
            $table->string('file_disk', 30)->nullable();                 // "local" = storage/app/private
            $table->string('file_path', 1024)->nullable();               // course-content/{id}/{name}
            $table->string('file_name')->nullable();                     // original name, for display
            $table->unsignedBigInteger('file_size')->nullable();         // bytes
            $table->string('file_mime', 100)->nullable();                // real type, read from the file itself
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_content');
    }
};
