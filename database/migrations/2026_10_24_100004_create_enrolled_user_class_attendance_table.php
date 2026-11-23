<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live class attendance, one row per enrollment and class. Written when the student joins through
 * the tracked Join link (present, or late after 10 minutes), or by an admin (marked_by set).
 * No row for a class that was held = absent; nothing is written for absent students.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrolled_user_class_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained('enrolled_user_list')->cascadeOnDelete();
            $table->foreignId('class_schedule_id')->constrained('batchwise_class_schedule')->cascadeOnDelete();
            $table->string('status', 20)->default('present');               // present | late | excused
            $table->timestamp('first_joined_at')->nullable();               // null when an admin marked it
            $table->timestamp('last_joined_at')->nullable();
            $table->unsignedSmallInteger('join_count')->default(0);
            $table->foreignId('marked_by')->nullable()->constrained('admins')->nullOnDelete();   // null = recorded by the Join link
            $table->timestamps();

            // short names: MySQL allows 64 characters
            $table->unique(['enrollment_id', 'class_schedule_id'], 'euca_enrollment_class_unique');
            $table->index('class_schedule_id', 'euca_class_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrolled_user_class_attendance');
    }
};
