<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The file formats admins may upload (resource library, assignment files, email attachments),
 * managed in System > File types. One row per group, e.g. "Adobe Illustrator" => ai, eps, svg ...
 * The client's "Supported File Formats" list is filled in by Database\Seeders\FileTypeSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->json('extensions');                       // ["ai", "eps", ...], lowercase, no dot
            $table->unsignedInteger('max_size_mb')->default(50);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_types');
    }
};
