<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per enrollment and curriculum item the student has finished (or been excused from).
 * No row = not done yet. How an item counts as done:
 *   recorded video    the student clicks "Mark as complete"          completed_via = marked
 *   live class        joined through the Join link                    attended
 *                     or marked its recording complete                recording
 *   resource          opened once                                     opened
 *   assignment, exam  file submitted                                  submitted
 *   any item          the admin excuses the student                   excused (status = excused)
 * Course progress = each module's milestone_value split across its counted items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrolled_user_course_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained('enrolled_user_list')->cascadeOnDelete();
            $table->foreignId('course_module_items_id')->constrained('course_module_items')->cascadeOnDelete();
            $table->foreignId('course_modules_id')->constrained('course_modules')->cascadeOnDelete();   // copied in for quick module totals
            $table->string('status', 20)->default('completed');             // completed | excused
            $table->string('completed_via', 20);                            // marked | attended | recording | opened | submitted | excused
            $table->foreignId('marked_by')->nullable()->constrained('admins')->nullOnDelete();   // the admin, for excused
            $table->timestamp('completed_at')->useCurrent();
            $table->timestamps();

            // short names: MySQL allows 64 characters
            $table->unique(['enrollment_id', 'course_module_items_id'], 'eucp_enrollment_item_unique');
            $table->index(['enrollment_id', 'course_modules_id'], 'eucp_enrollment_module_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrolled_user_course_progress');
    }
};
