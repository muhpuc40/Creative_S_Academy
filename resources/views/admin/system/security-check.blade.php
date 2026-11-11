<x-admin.layout title="Security Check">
    @php($areaCards = [
        ['id' => 'files', 'icon' => 'folder', 'title' => 'Uploaded files'],
        ['id' => 'config', 'icon' => 'sliders', 'title' => 'Configuration & environment'],
        ['id' => 'storage', 'icon' => 'monitor', 'title' => 'Storage & system'],
        ['id' => 'uploads', 'icon' => 'upload', 'title' => 'Upload system health'],
    ])
    <div x-data="securityCheck({
            startUrl: '{{ route('admin.security-check.start') }}',
            filesUrl: '{{ route('admin.security-check.files') }}',
            checkUrl: '{{ url('system/security-check/check') }}',
            quarantineBase: '{{ url('system/security-check/quarantine') }}',
            checks: {{ \Illuminate\Support\Js::from(array_column($health, 'id')) }},
         })">

        <x-admin.page-header title="Security at a glance"
            description="Scan uploads for hidden scripts and forbidden files; risky files are quarantined for review.">
            <x-admin.button type="button" @click="scan()" x-bind:disabled="scanning">
                <x-admin.icon name="shield" class="size-4" />
                <span x-text="scanning ? 'Scanning…' : 'Scan now'"></span>
            </x-admin.button>
        </x-admin.page-header>

        {{-- At-a-glance grid --}}
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ($areaCards as $a)
                <div class="rounded-2xl border p-5 transition-colors" :class="cardClass(areas['{{ $a['id'] }}'].status)">
                    <div class="flex items-start gap-4">
                        <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-slate-900/5"
                            :class="iconWrapClass(areas['{{ $a['id'] }}'].status)">
                            <x-admin.icon name="{{ $a['icon'] }}" class="size-6" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <h3 class="font-semibold text-slate-900">{{ $a['title'] }}</h3>
                                <span class="size-2 rounded-full" :class="dotClass(areas['{{ $a['id'] }}'].status)"></span>
                            </div>
                            <p class="mt-1 text-sm" :class="textClass(areas['{{ $a['id'] }}'].status)"
                                x-text="areas['{{ $a['id'] }}'].text"></p>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Quarantine --}}
        <x-admin.card title="Quarantine" class="mt-6">
            <p class="mb-3 text-sm text-slate-500">Files the scan moved out of the uploads folders. Restore a false positive, or delete it for good.</p>
            <div id="quar-list" class="divide-y divide-slate-100">
                @forelse ($quarantined as $q)
                    <div id="quar-{{ $q->id }}" class="flex items-center justify-between gap-3 py-3">
                        <div class="min-w-0">
                            <p class="truncate font-medium text-slate-900">{{ $q->display_path }}</p>
                            <p class="truncate text-xs text-slate-500">{{ $q->reason }} · {{ number_format($q->size / 1024, 1) }} KB · {{ $q->created_at->diffForHumans() }}</p>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            <button type="button" class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-100"
                                @click="restore({{ $q->id }})">Restore</button>
                            <button type="button" class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50"
                                @click="if (confirm('Delete this file permanently?')) remove({{ $q->id }})">Delete</button>
                        </div>
                    </div>
                @empty
                    <p id="quar-empty" class="py-6 text-center text-sm text-slate-500">Nothing in quarantine.</p>
                @endforelse
            </div>
        </x-admin.card>

        {{-- Scan modal (custom, so it cannot be closed mid-scan) --}}
        <div x-show="open" x-cloak class="fixed inset-0 z-[60] overflow-y-auto" role="dialog" aria-modal="true">
            <div class="flex min-h-full items-start justify-center p-4 sm:items-center">
                <div x-show="open" x-transition.opacity class="fixed inset-0 bg-slate-900/60" @click="close()"></div>

                <div x-show="open" x-transition.scale.95 class="relative my-8 w-full max-w-2xl rounded-2xl bg-white shadow-xl">
                    <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                        <h3 class="flex items-center gap-2 text-base font-semibold text-slate-900">
                            <x-admin.icon name="shield" class="size-5 text-indigo-600" />
                            <span x-text="phase === 'done' ? 'Scan complete' : 'Security scan running'"></span>
                        </h3>
                        <button type="button" x-show="!scanning" class="rounded-md p-1 text-slate-400 hover:text-slate-600"
                            @click="close()" aria-label="Close">
                            <x-admin.icon name="x" />
                        </button>
                    </div>

                    <div class="px-6 py-5">
                        {{-- progress bar --}}
                        <div class="mb-1.5 flex items-center justify-between gap-3 text-sm">
                            <span class="font-medium text-slate-700" x-text="progressLabel || 'Starting…'"></span>
                            <span class="text-slate-500" x-text="percent + '%'"></span>
                        </div>
                        <div class="h-2.5 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full transition-all duration-300"
                                :class="phase === 'done' ? doneBarClass() : 'bg-indigo-500'"
                                :style="'width: ' + percent + '%'"></div>
                        </div>

                        {{-- keep-open warning while scanning --}}
                        <div x-show="scanning" class="mt-3 flex items-center gap-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                            <x-admin.icon name="alert-circle" class="size-4 shrink-0" />
                            <span>Scan in progress — please keep this window open until it finishes.</span>
                        </div>

                        {{-- counters --}}
                        <div class="mt-4 grid grid-cols-3 gap-2 text-center">
                            <div class="rounded-lg bg-red-50 py-2">
                                <p class="text-lg font-semibold text-red-700" x-text="counts.threats"></p>
                                <p class="text-xs text-red-600">Threats</p>
                            </div>
                            <div class="rounded-lg bg-red-50/60 py-2">
                                <p class="text-lg font-semibold text-red-700" x-text="counts.failures"></p>
                                <p class="text-xs text-red-600">Failures</p>
                            </div>
                            <div class="rounded-lg bg-amber-50 py-2">
                                <p class="text-lg font-semibold text-amber-700" x-text="counts.warnings"></p>
                                <p class="text-xs text-amber-600">Warnings</p>
                            </div>
                        </div>

                        {{-- live log --}}
                        <div class="mt-4 max-h-64 overflow-y-auto rounded-lg border border-slate-100 bg-slate-50/60 p-3 text-sm">
                            <template x-for="(entry, i) in log" :key="i">
                                <div class="flex items-start justify-between gap-3 border-b border-slate-100 py-1.5 last:border-0">
                                    <div class="flex items-start gap-2">
                                        <span class="mt-1.5 size-2 shrink-0 rounded-full" :class="logDot(entry.type)"></span>
                                        <span :class="logClass(entry.type)" x-text="entry.text"></span>
                                    </div>
                                    <template x-if="entry.finding">
                                        <div class="flex shrink-0 gap-1">
                                            <button type="button" class="rounded px-2 py-0.5 text-xs text-slate-600 hover:bg-slate-200"
                                                @click="restore(entry.finding.id); entry.finding = null">Restore</button>
                                            <button type="button" class="rounded px-2 py-0.5 text-xs text-red-600 hover:bg-red-100"
                                                @click="if (confirm('Delete permanently?')) { remove(entry.finding.id); entry.finding = null }">Delete</button>
                                        </div>
                                    </template>
                                </div>
                            </template>
                            <p x-show="log.length === 0" class="py-4 text-center text-slate-400">No findings yet…</p>
                        </div>
                    </div>

                    <div class="flex items-center justify-between gap-3 border-t border-slate-100 px-6 py-4">
                        <p class="text-sm" :class="phase === 'done' ? doneTextClass() : 'text-slate-500'"
                            x-text="phase === 'done' ? summaryLine() : 'Working…'"></p>
                        <x-admin.button type="button" variant="secondary" @click="close()" x-bind:disabled="scanning">
                            <span x-text="scanning ? 'Please wait…' : 'Close'"></span>
                        </x-admin.button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            function securityCheck(cfg) {
                return {
                    cfg,
                    open: false,
                    scanning: false,
                    phase: 'idle',
                    percent: 0,
                    progressLabel: '',
                    log: [],
                    counts: { threats: 0, failures: 0, warnings: 0 },
                    areas: {
                        files: { status: 'idle', text: 'Not scanned yet' },
                        config: { status: 'idle', text: 'Not scanned yet' },
                        storage: { status: 'idle', text: 'Not scanned yet' },
                        uploads: { status: 'idle', text: 'Not scanned yet' },
                    },

                    init() {
                        window.addEventListener('beforeunload', (e) => {
                            if (this.scanning) { e.preventDefault(); e.returnValue = ''; }
                        });
                    },

                    csrf() { return document.querySelector('meta[name=csrf-token]').content; },

                    async post(url, body) {
                        const r = await fetch(url, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                            body: JSON.stringify(body || {}),
                        });
                        if (!r.ok) throw new Error('Request failed (' + r.status + ')');
                        return r.json();
                    },

                    async del(url) {
                        const r = await fetch(url, { method: 'DELETE', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf() } });
                        return r.json();
                    },

                    async scan() {
                        if (this.scanning) return;
                        this.open = true;
                        this.scanning = true;
                        this.phase = 'running';
                        this.percent = 0;
                        this.log = [];
                        this.counts = { threats: 0, failures: 0, warnings: 0 };
                        for (const id of Object.keys(this.areas)) this.areas[id] = { status: 'scanning', text: 'Scanning…' };

                        try {
                            await this.scanFiles();
                            let i = 0;
                            for (const id of this.cfg.checks) {
                                await this.runCheck(id);
                                i++;
                                this.percent = 70 + Math.round(i * (30 / this.cfg.checks.length));
                            }
                            this.percent = 100;
                            this.phase = 'done';
                            this.progressLabel = 'Done';
                            this.pushLog('info', this.summaryLine());
                        } catch (e) {
                            this.pushLog('fail', 'Scan stopped: ' + e.message);
                            this.phase = 'done';
                        } finally {
                            this.scanning = false;
                        }
                    },

                    async scanFiles() {
                        this.progressLabel = 'Preparing file list…';
                        const s = await this.post(this.cfg.startUrl, {});
                        const total = s.total;
                        let offset = 0, done = total === 0, found = 0;
                        this.percent = total === 0 ? 70 : 0;
                        this.progressLabel = 'Scanning uploaded files… 0 / ' + total;

                        while (!done) {
                            const b = await this.post(this.cfg.filesUrl, { token: s.token, offset });
                            offset = b.next_offset;
                            done = b.done;
                            this.percent = total > 0 ? Math.round((b.scanned / total) * 70) : 70;
                            this.progressLabel = 'Scanning uploaded files… ' + b.scanned + ' / ' + total;
                            for (const f of b.findings) {
                                found++;
                                this.counts.threats++;
                                this.pushLog('threat', f.reason + ' — ' + f.path, f);
                            }
                        }
                        this.areas.files = found > 0
                            ? { status: 'threat', text: found + ' threat(s) quarantined' }
                            : { status: 'ok', text: total + ' file(s) scanned · no threats' };
                    },

                    async runCheck(id) {
                        this.progressLabel = 'Checking ' + id + '…';
                        const r = await this.post(this.cfg.checkUrl + '/' + id, {});
                        for (const it of r.items) {
                            if (it.status === 'warn') { this.counts.warnings++; this.pushLog('warn', it.label + ': ' + it.detail); }
                            else if (it.status === 'fail') { this.counts.failures++; this.pushLog('fail', it.label + ': ' + it.detail); }
                        }
                        const bad = r.items.filter((it) => it.status !== 'ok').length;
                        this.areas[id] = { status: r.status, text: bad === 0 ? 'No action needed.' : bad + ' item(s) need attention' };
                    },

                    async restore(id) {
                        const r = await this.post(this.cfg.quarantineBase + '/' + id + '/restore', {});
                        if (r.ok) this.dropRow(id);
                        else alert(r.error || 'Could not restore the file.');
                    },

                    async remove(id) {
                        const r = await this.del(this.cfg.quarantineBase + '/' + id);
                        if (r.ok) this.dropRow(id);
                    },

                    dropRow(id) {
                        const el = document.getElementById('quar-' + id);
                        if (el) el.remove();
                        if (!document.querySelector('#quar-list > div')) {
                            const empty = document.getElementById('quar-empty');
                            if (empty) empty.style.display = '';
                        }
                    },

                    pushLog(type, text, finding) { this.log.push({ type, text, finding: finding || null }); },

                    summaryLine() {
                        const { threats: t, failures: f, warnings: w } = this.counts;
                        if (!t && !f && !w) return 'All clear — no action needed.';
                        const parts = [];
                        if (t) parts.push(t + ' threat' + (t > 1 ? 's' : '') + ' quarantined');
                        if (f) parts.push(f + ' failure' + (f > 1 ? 's' : ''));
                        if (w) parts.push(w + ' warning' + (w > 1 ? 's' : ''));
                        return parts.join(', ') + '.';
                    },

                    close() { if (!this.scanning) this.open = false; },

                    /* styling helpers */
                    cardClass(s) { return ({ idle: 'border-slate-200 bg-white', scanning: 'border-sky-200 bg-sky-50/40', ok: 'border-emerald-200 bg-white', warn: 'border-amber-200 bg-amber-50/40', fail: 'border-red-200 bg-red-50/40', threat: 'border-red-200 bg-red-50/40' })[s] || 'border-slate-200 bg-white'; },
                    iconWrapClass(s) { return ({ idle: 'text-slate-500', scanning: 'text-sky-600', ok: 'text-emerald-600', warn: 'text-amber-600', fail: 'text-red-600', threat: 'text-red-600' })[s] || 'text-slate-500'; },
                    dotClass(s) { return ({ idle: 'bg-slate-300', scanning: 'bg-sky-500 animate-pulse', ok: 'bg-emerald-500', warn: 'bg-amber-500', fail: 'bg-red-500', threat: 'bg-red-500' })[s] || 'bg-slate-300'; },
                    textClass(s) { return ({ idle: 'text-slate-500', scanning: 'text-sky-700', ok: 'text-slate-500', warn: 'text-amber-700', fail: 'text-red-700', threat: 'text-red-700' })[s] || 'text-slate-500'; },
                    logClass(t) { return ({ threat: 'text-red-700', fail: 'text-red-700', warn: 'text-amber-700', ok: 'text-emerald-700', info: 'text-slate-600' })[t] || 'text-slate-600'; },
                    logDot(t) { return ({ threat: 'bg-red-500', fail: 'bg-red-500', warn: 'bg-amber-500', ok: 'bg-emerald-500', info: 'bg-slate-400' })[t] || 'bg-slate-400'; },
                    doneBarClass() { return this.counts.threats || this.counts.failures ? 'bg-red-500' : (this.counts.warnings ? 'bg-amber-500' : 'bg-emerald-500'); },
                    doneTextClass() { return this.counts.threats || this.counts.failures ? 'text-red-700' : (this.counts.warnings ? 'text-amber-700' : 'text-emerald-700'); },
                };
            }
        </script>
    @endpush
</x-admin.layout>
