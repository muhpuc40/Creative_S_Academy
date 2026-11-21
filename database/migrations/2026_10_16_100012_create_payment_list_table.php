<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Money the student sent. One payment can pay several dues. Never deleted: reject it.
        Schema::create('payment_list', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_list_id')->constrained('order_list')->restrictOnDelete();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->string('method', 20);                                  // mfs | cash | bank
            $table->foreignId('payment_account_id')->nullable()->constrained('payment_accounts')->nullOnDelete();   // which of our accounts
            $table->string('provider', 60)->nullable();                    // bkash / nagad / rocket, or the bank name
            $table->string('sender_account', 60)->nullable();              // student's MFS number or bank account
            $table->string('trx_id', 60)->nullable();                      // TrxID, bank reference or cash receipt no. Checked in code: no reuse.
            $table->decimal('amount', 12, 2);
            $table->timestamp('paid_at')->nullable();                      // time the student says he paid
            $table->string('status', 20)->default('pending');              // pending | verified | rejected
            $table->foreignId('verified_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('reject_reason')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['order_list_id', 'status']);
            $table->index(['status', 'created_at']);                       // admin queue
            $table->index(['method', 'trx_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_list');
    }
};
