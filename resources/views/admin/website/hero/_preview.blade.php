{{--
  Picture of one slide, drawn the way the website draws it.
  $slide = Alpine expression returning {title, subtitle, button_text, bg_color, bg_image, image}
--}}
<div x-data="{ s: {} }" x-effect="s = {{ $slide }}"
    class="relative flex aspect-[16/10] items-center gap-3 overflow-hidden rounded-xl bg-violet-50 bg-cover bg-center p-4 ring-1 ring-slate-900/10"
    :style="slideStyle(s)">
    {{-- the website darkens a background image behind the text the same way --}}
    <div x-show="s.bg_image" class="absolute inset-0 bg-gradient-to-r from-slate-900/80 via-slate-900/50 to-slate-900/10"></div>
    <div class="relative min-w-0 flex-1">
        <p class="line-clamp-3 text-base font-bold leading-snug" :class="lightText(s) ? 'text-white' : 'text-slate-900'" x-text="s.title || 'Title'"></p>
        <p x-show="s.subtitle" class="mt-1 line-clamp-3 text-xs" :class="lightText(s) ? 'text-white/80' : 'text-slate-600'" x-text="s.subtitle"></p>
        <span x-show="s.button_text" class="mt-2 inline-block rounded-full px-3 py-1 text-xs font-semibold"
            :class="lightText(s) ? 'bg-white text-violet-700' : 'bg-violet-600 text-white'" x-text="s.button_text"></span>
    </div>
    <img x-show="s.image" :src="s.image" alt="" class="relative aspect-[4/3] w-2/5 shrink-0 rounded-lg object-cover shadow-lg">
</div>
