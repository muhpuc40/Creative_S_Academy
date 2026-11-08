<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Combo offer: several courses sold together. Created by admin. Always calculated from the courses' CURRENT prices.
        Schema::create('payment_combos', function (Blueprint $table) {
            $table->id();
            $table->string('title');                                       // New Year Offer
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('thumbnail')->nullable();
            $table->string('price_type', 10);                              // fixed (the combo price) | percent (discount on the sum)
            $table->decimal('price_value', 12, 2);                         // 15000 (taka) or 10 (percent)
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at');                                  // deadline
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_combos');
    }
};
