<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Google Cloud OAuth client (Client ID + secret) used for Google Meet.
 * Set in System > Google Meet, so it can change without a redeploy. One row at most.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_settings', function (Blueprint $table) {
            $table->id();
            $table->string('client_id');
            $table->text('client_secret');                       // encrypted
            $table->foreignId('updated_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_settings');
    }
};
