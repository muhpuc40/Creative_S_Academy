{{-- Built from App\Enums\DisplayAction: a new action appears here without touching this file. --}}
<x-admin.layout title="Hero settings">
    <x-admin.page-header title="Hero settings"
        description="Choose what the home page hero shows and how the slider moves. The website updates within a few minutes." />

    <form method="POST" action="{{ route('admin.website.hero-settings.update') }}" class="space-y-6">
        @csrf
        @method('PUT')

        <div class="grid items-start gap-6 lg:grid-cols-2">
            {{-- groups: exactly one action of the group is on (if none is saved yet, its default is picked) --}}
            @foreach ($groups as $group => $actions)
                @php
                    $actions = collect($actions);
                    $chosen = old("groups.$group", ($actions->first(fn ($a) => $settings[$a->value]['status'])
                        ?? $actions->first(fn ($a) => $a->defaultStatus()) ?? $actions->first())->value);
                @endphp
                <x-admin.card :title="\App\Enums\DisplayAction::groupLabel($group)">
                    <fieldset class="space-y-3">
                        <legend class="sr-only">{{ \App\Enums\DisplayAction::groupLabel($group) }}</legend>
                        @foreach ($actions as $action)
                            <label class="flex cursor-pointer items-start gap-3 rounded-xl p-4 ring-1 ring-inset ring-slate-200 has-[:checked]:bg-indigo-50/60 has-[:checked]:ring-indigo-500">
                                <input type="radio" name="groups[{{ $group }}]" value="{{ $action->value }}" @checked($chosen === $action->value)
                                    class="mt-0.5 size-4 border-slate-300 text-indigo-600 focus:ring-indigo-600">
                                <span>
                                    <span class="block text-sm font-semibold text-slate-900">{{ $action->label() }}</span>
                                    <span class="block text-sm text-slate-500">{{ $action->description() }}</span>
                                    @if ($action === \App\Enums\DisplayAction::HeroContent)
                                        <span class="mt-1 block text-xs {{ $activeSlides ? 'text-slate-500' : 'font-medium text-amber-700' }}">
                                            @if ($activeSlides)
                                                {{ $activeSlides }} {{ Str::plural('slide', $activeSlides) }} shown.
                                            @else
                                                No slide is shown yet, so the website keeps showing the course list.
                                            @endif
                                            <a href="{{ route('admin.website.hero.index') }}" class="font-semibold text-indigo-600 hover:underline">Manage slides</a>
                                        </span>
                                    @endif
                                </span>
                            </label>
                        @endforeach
                        @error("groups.$group")
                            <p class="text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </fieldset>
                </x-admin.card>
            @endforeach

            {{-- actions without a group: on/off, plus a value when the action has options --}}
            @if ($standalone)
                <x-admin.card title="Slider">
                    <div class="divide-y divide-slate-100">
                        @foreach ($standalone as $action)
                            <div class="flex flex-col gap-3 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between">
                                <label class="flex cursor-pointer items-start gap-3">
                                    <input type="hidden" name="actions[{{ $action->value }}][status]" value="0">
                                    <input type="checkbox" name="actions[{{ $action->value }}][status]" value="1"
                                        @checked(old("actions.{$action->value}.status", $settings[$action->value]['status']))
                                        class="mt-0.5 size-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-600">
                                    <span>
                                        <span class="block text-sm font-semibold text-slate-900">{{ $action->label() }}</span>
                                        <span class="block text-sm text-slate-500">{{ $action->description() }}</span>
                                    </span>
                                </label>
                                @if ($action->options())
                                    <div class="sm:w-56">
                                        <x-admin.select id="action_{{ $action->value }}" name="actions[{{ $action->value }}][value]"
                                            :value="(string) $settings[$action->value]['value']" aria-label="{{ $action->label() }}">
                                            @foreach ($action->options() as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </x-admin.select>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </x-admin.card>
            @endif
        </div>

        <div class="flex justify-end">
            <x-admin.button type="submit">Save settings</x-admin.button>
        </div>
    </form>
</x-admin.layout>
