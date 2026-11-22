<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Meta (Facebook) Pixel of the student website. Set in System > Meta Pixel, so it can change
 * at any time without a redeploy; the website gets it from GET /api/v1/home. One row (id = 1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_pixel_settings', function (Blueprint $table) {
            $table->id();
            $table->string('pixel_id', 20)->nullable();           // digits only, e.g. 1234567890123456
            $table->boolean('is_active')->default(false);        // off: the website loads no pixel (the ID is kept)
            $table->foreignId('updated_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_pixel_settings');
    }
};
