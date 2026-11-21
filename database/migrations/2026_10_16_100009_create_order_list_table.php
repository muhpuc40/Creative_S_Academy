<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // One checkout. It can hold one or many courses (order_items). Never deleted: cancel it.
        Schema::create('order_list', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 30)->unique();                      // shown to the student: CSA-2610-0001
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('affiliate_attribution_id')->nullable()->constrained('affiliate_attributions')->nullOnDelete();
            $table->foreignId('payment_combo_id')->nullable()->constrained('payment_combos')->nullOnDelete();
            $table->foreignId('payment_voucher_id')->nullable()->constrained('payment_vouchers')->nullOnDelete();
            $table->string('status', 20)->default('pending');              // pending | partially_paid | paid | cancelled | expired
            $table->decimal('subtotal', 12, 2)->default(0);                // sum of the courses' current prices (after the course's own discount)
            $table->decimal('combo_discount', 12, 2)->default(0);
            $table->decimal('voucher_discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);                   // subtotal - combo_discount - voucher_discount (0 for free courses)
            $table->decimal('paid_amount', 12, 2)->default(0);             // verified money so far
            $table->timestamp('expires_at')->nullable();                   // an unpaid order is cancelled after this (frees the seat and the voucher)
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_list');
    }
};
