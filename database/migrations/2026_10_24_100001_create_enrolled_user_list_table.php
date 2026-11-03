<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // One course a student has access to: one row per order_item, not per order - a 3-course
        // order can enroll course A while B and C are still unpaid. Created the moment that
        // course's first installment is verified paid (PaymentService), which is also when the
        // matching affiliate_conversions row (if any) is made - see that table's own comment.
        Schema::create('enrolled_user_list', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('order_item_id')->unique()->constrained('order_items')->restrictOnDelete();
            $table->foreignId('course_list_id')->constrained('course_list')->restrictOnDelete();   // denormalized for fast "my courses" lookups
            $table->foreignId('batch_list_id')->constrained('batch_list')->restrictOnDelete();
            $table->string('status', 20)->default('active');               // active | revoked
            $table->timestamp('enrolled_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'status']);
            $table->index(['course_list_id', 'batch_list_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrolled_user_list');
    }
};
