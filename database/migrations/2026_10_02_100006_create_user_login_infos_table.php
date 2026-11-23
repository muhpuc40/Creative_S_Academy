<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Login history for all user types. user_id is null when the account was not found.
        Schema::create('user_login_infos', function (Blueprint $table) {
            $table->id();
            $table->string('user_type', 20)->index();                  // panel used: admin, student, instructor
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('login_identifier')->nullable();            // the email or phone that was typed
            $table->string('status', 20)->index();                     // success, failed, blocked
            $table->string('failure_reason', 50)->nullable();          // wrong_password, suspended, device_limit
            $table->string('device_id', 100)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_type', 'user_id']);
            $table->index('created_at');                                    // the login history lists newest first
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_login_infos');
    }
};
