<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website popups. Two tables:
 *  - popups:          what the popup shows (Website > Popups)
 *  - popup_settings:  when and where it shows, one row per popup
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('popups', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20)->index();           // App\Enums\PopupKind: modal / corner
            $table->string('image')->nullable();           // path on the "public" disk; required for corner
            $table->string('title', 150)->nullable();      // modal only
            $table->string('body', 1000)->nullable();      // modal only
            $table->string('button_text', 60)->nullable(); // modal only
            $table->string('link', 2048)->nullable();      // the button's link, or where the corner image goes
            $table->timestamps();
        });

        Schema::create('popup_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('popup_id')->unique()->constrained('popups')->cascadeOnDelete();
            $table->boolean('is_active')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);  // first match wins
            $table->json('pages');                                    // ["*"] all, ["/"] home, or ["/courses/*", ...]
            $table->unsignedSmallInteger('delay_seconds')->default(3); // counted from the visitor's arrival on the site
            $table->string('frequency', 20)->default('per_visit');    // App\Enums\PopupFrequency
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('popup_settings');
        Schema::dropIfExists('popups');
    }
};
