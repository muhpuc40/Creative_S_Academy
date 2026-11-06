<?php

namespace App\Models;

use App\Enums\DisplayAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * One switch of what the website shows (Website > Hero settings). The list of switches
 * lives in App\Enums\DisplayAction; a missing row simply means "use the default".
 */
class HeroSetting extends Model
{
    protected $table = 'hero_settings';

    protected $fillable = ['action_name', 'status', 'value'];

    protected function casts(): array
    {
        return [
            'action_name' => DisplayAction::class,
            'status' => 'boolean',
        ];
    }

    /**
     * Every action with its current status and value (defaults filled in), keyed by action name:
     *   ['default_course_list' => ['status' => true, 'value' => null], ...]
     */
    public static function current(): array
    {
        $rows = static::all()->keyBy(fn (HeroSetting $row) => $row->action_name->value);

        $settings = [];
        foreach (DisplayAction::cases() as $action) {
            $row = $rows->get($action->value);
            $settings[$action->value] = [
                'status' => $row ? $row->status : $action->defaultStatus(),
                'value' => $action->cast($row?->value),
            ];
        }

        return $settings;
    }

    /** Is this action switched on right now? */
    public static function isOn(DisplayAction $action): bool
    {
        return static::current()[$action->value]['status'];
    }

    /**
     * Save many actions at once: [action_name => ['status' => bool, 'value' => ?string]].
     * Keeps the rule "one action per group": if two of a group come in as on, the request
     * was already rejected by validation (HeroSettingRequest).
     */
    public static function saveMany(array $settings): void
    {
        DB::transaction(function () use ($settings) {
            foreach ($settings as $name => $setting) {
                static::updateOrCreate(
                    ['action_name' => $name],
                    ['status' => (bool) $setting['status'], 'value' => $setting['value'] ?? null],
                );
            }
        });
    }
}
