<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Price of the whole course. Keep old rows (is_active = false) as price history.
     * Access and renewal belong to the price too, so they keep the same history:
     *  access_days   how long a new enrollment can open videos, recordings and resources
     *  renewal_fee   one renewal, paid in full. null = renewal not offered
     *  renewal_days  days one renewal adds (counted from the old expiry, or today if that has passed)
     */
    public function up(): void
    {
        Schema::create('course_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_list_id')->constrained('course_list')->cascadeOnDelete();
            $table->decimal('actual_price', 12, 2);
            $table->enum('discount_type', ['percent', 'flat'])->nullable();
            $table->decimal('discount_value', 10, 2)->default(0);
            $table->unsignedSmallInteger('access_days')->default(180);
            $table->decimal('renewal_fee', 12, 2)->nullable();
            $table->unsignedSmallInteger('renewal_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['course_list_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_prices');
    }
};
