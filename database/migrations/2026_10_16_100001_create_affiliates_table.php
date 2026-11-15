<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Affiliate login account. Same shape as students / instructors / admins.
        Schema::create('affiliates', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone', 20)->nullable()->unique();
            $table->timestamp('verified_at')->nullable();
            $table->string('verified_channel', 20)->nullable();
            $table->string('password');
            $table->string('avatar')->nullable();
            $table->string('status', 20)->default('active')->index();   // account: active | suspended (UserStatus)
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliates');
    }
};
