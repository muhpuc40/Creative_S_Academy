<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Voucher: made by admin for ONE student, usable ONCE. The student gets an email.
        // The order that uses it is order_list.payment_voucher_id.
        Schema::create('payment_vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();   // only this student can use it
            $table->string('title')->nullable();
            $table->string('amount_type', 10);                             // percent | flat
            $table->decimal('amount', 10, 2);
            $table->dateTime('expires_at');                                // deadline. dateTime, not timestamp: on MariaDB/MySQL with explicit_defaults_for_timestamp=OFF (XAMPP) a NOT NULL timestamp silently gets ON UPDATE CURRENT_TIMESTAMP
            $table->string('status', 20)->default('active');               // active | used | cancelled  (expired = active + past expires_at)
            $table->timestamp('used_at')->nullable();                      // set when an order takes it, cleared if that order is cancelled
            $table->timestamp('emailed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_vouchers');
    }
};
