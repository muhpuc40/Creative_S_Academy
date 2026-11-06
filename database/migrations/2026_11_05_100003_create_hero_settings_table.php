<?php

use App\Enums\DisplayAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the website shows, as switches: one row per action (see App\Enums\DisplayAction).
 *   action_name  e.g. "default_course_list", "hero_content", "hero_slider_interval"
 *   status       on / off
 *   value        extra setting when the action needs one (e.g. the slide time in ms)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hero_settings', function (Blueprint $table) {
            $table->id();
            $table->string('action_name', 60)->unique();
            $table->boolean('status')->default(false);
            $table->string('value')->nullable();
            $table->timestamps();
        });

        // start with the defaults: the course list on, hero content off, auto-slide every 5 s
        $now = now();
        DB::table('hero_settings')->insert(array_map(fn (DisplayAction $action) => [
            'action_name' => $action->value,
            'status' => $action->defaultStatus(),
            'value' => $action->defaultValue(),
            'created_at' => $now,
            'updated_at' => $now,
        ], DisplayAction::cases()));
    }

    public function down(): void
    {
        Schema::dropIfExists('hero_settings');
    }
};
