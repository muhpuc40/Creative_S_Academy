<?php

namespace App\Http\Requests\Admin;

use App\Enums\DisplayAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Save Website > Hero settings. Works for any action in App\Enums\DisplayAction:
 *   groups[<group>]          = the one action of that group to turn on (exactly one is always on)
 *   actions[<name>][status]  = 1 / 0  (actions without a group)
 *   actions[<name>][value]   = one of the action's options (actions that have options)
 */
class HeroSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [];

        foreach (self::groups() as $group => $actions) {
            $rules["groups.$group"] = ['required', Rule::in(array_map(fn (DisplayAction $a) => $a->value, $actions))];
        }

        foreach (DisplayAction::cases() as $action) {
            if ($action->group() === null) {
                $rules["actions.{$action->value}.status"] = ['nullable', 'boolean'];
            }
            if ($action->options() !== null) {
                $rules["actions.{$action->value}.value"] = ['required', Rule::in(array_map('strval', array_keys($action->options())))];
            }
        }

        return $rules;
    }

    public function attributes(): array
    {
        $names = [];
        foreach (array_keys(self::groups()) as $group) {
            $names["groups.$group"] = DisplayAction::groupLabel($group);
        }
        foreach (DisplayAction::cases() as $action) {
            $names["actions.{$action->value}.value"] = $action->label();
        }

        return $names;
    }

    /** [action_name => ['status' => bool, 'value' => ?string]] for every action, ready for HeroSetting::saveMany(). */
    public function settings(): array
    {
        $settings = [];

        foreach (DisplayAction::cases() as $action) {
            $group = $action->group();
            $settings[$action->value] = [
                // in a group only the chosen one is on, so two of a group can never be on together
                'status' => $group !== null
                    ? $this->input("groups.$group") === $action->value
                    : $this->boolean("actions.{$action->value}.status"),
                'value' => $action->options() !== null ? (string) $this->input("actions.{$action->value}.value") : null,
            ];
        }

        return $settings;
    }

    /** Actions that belong to a group: [group => [DisplayAction, ...]]. */
    public static function groups(): array
    {
        $groups = [];
        foreach (DisplayAction::cases() as $action) {
            if ($action->group() !== null) {
                $groups[$action->group()][] = $action;
            }
        }

        return $groups;
    }
}
