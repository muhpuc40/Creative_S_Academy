<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // One course (in one batch) inside an order. Prices are copied, so later price changes never touch old orders.
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_list_id')->constrained('order_list')->cascadeOnDelete();
            $table->foreignId('course_list_id')->constrained('course_list')->restrictOnDelete();
            $table->foreignId('batch_list_id')->constrained('batch_list')->restrictOnDelete();
            $table->foreignId('course_prices_id')->constrained('course_prices')->restrictOnDelete();   // the immutable price row used
            // A renewal: this item extends that enrollment's access (paid in full at the price's renewal_fee,
            // no new enrollment or affiliate commission). null = a normal purchase. Its foreign key is added
            // in the enrolled_user_list migration, because that table is created after this one.
            $table->foreignId('renews_enrollment_id')->nullable();
            $table->timestamp('renewed_until')->nullable();               // a paid renewal: the access end it set (also marks it as applied)
            $table->string('payment_plan', 20)->default('full');           // full | installment
            $table->decimal('list_price', 12, 2);                          // course actual price
            $table->decimal('sale_price', 12, 2);                          // current price after the course's own discount
            $table->decimal('combo_discount', 12, 2)->default(0);          // this course's share of the combo discount
            $table->decimal('voucher_discount', 12, 2)->default(0);        // this course's share of the voucher
            $table->decimal('net_price', 12, 2);                           // what the student owes for this course
            $table->string('status', 20)->default('active');               // active | cancelled | refunded
            $table->timestamps();

            $table->unique(['order_list_id', 'course_list_id']);           // one course once per order
            $table->index(['batch_list_id', 'status']);                    // seats taken
            $table->index('course_list_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
