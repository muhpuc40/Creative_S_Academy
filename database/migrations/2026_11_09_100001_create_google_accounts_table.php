<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The academy's Google account, connected once in System > Google Meet.
 * Used to create Google Meet links for live classes. One row at most.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->text('refresh_token');                       // encrypted
            $table->text('access_token')->nullable();            // encrypted, lasts about an hour
            $table->timestamp('access_token_expires_at')->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_accounts');
    }
};
