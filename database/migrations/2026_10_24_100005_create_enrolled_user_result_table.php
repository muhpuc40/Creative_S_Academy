<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A student's submission and marks for one batch exam or assignment. Exactly one of
 * batchwise_exam_id / batchwise_assignment_id is set, matching type. The file lives in Laravel's
 * private storage (file_path). There is no pass or fail; marks are shown to the student only
 * after the exam's result_published_at.
 *   submitted  file received, not marked yet
 *   graded     marks entered
 *   absent     nothing submitted, the admin recorded it (0 marks)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrolled_user_result', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained('enrolled_user_list')->cascadeOnDelete();
            $table->string('type', 20);                                     // exam | assignment
            $table->foreignId('batchwise_exam_id')->nullable()->constrained('batchwise_exam')->cascadeOnDelete();
            $table->foreignId('batchwise_assignment_id')->nullable()->constrained('batchwise_assignment')->cascadeOnDelete();
            $table->foreignId('course_module_items_id')->constrained('course_module_items')->restrictOnDelete();

            // the submission
            $table->string('file_path')->nullable();                        // private storage
            $table->string('file_name')->nullable();                        // original name, for downloads
            $table->unsignedBigInteger('file_size')->nullable();            // bytes
            $table->timestamp('submitted_at')->nullable();
            $table->boolean('is_late')->default(false);

            // the marking
            $table->string('status', 20)->default('submitted');             // submitted | graded | absent
            $table->decimal('marks_obtained', 6, 2)->nullable();
            $table->unsignedSmallInteger('total_marks')->nullable();        // copied when marked
            $table->text('feedback')->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('graded_at')->nullable();
            $table->timestamps();

            // nulls never clash; short names: MySQL allows 64 characters
            $table->unique(['enrollment_id', 'batchwise_exam_id'], 'eur_enrollment_exam_unique');
            $table->unique(['enrollment_id', 'batchwise_assignment_id'], 'eur_enrollment_assignment_unique');
            $table->index(['batchwise_exam_id', 'status'], 'eur_exam_status_index');
            $table->index(['batchwise_assignment_id', 'status'], 'eur_assignment_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrolled_user_result');
    }
};
