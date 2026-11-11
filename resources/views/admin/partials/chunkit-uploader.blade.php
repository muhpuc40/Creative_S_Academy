{{--
  Resumable chunked upload (muhpuc40/chunkit). The form gets only the finished upload's id
  in a hidden chunkit_upload_id field; the controller moves the file into place.
  The page's x-data listens to @uploader-busy.window (block Save while uploading) and
  dispatches uploader-reset when its dialog opens.

  @include('admin.partials.chunkit-uploader', ['id' => ..., 'label' => ..., 'accept' => ..., 'hint' => ...,
           'limits' => ['pdf' => 20, ...]])   limits (optional): extension => max MB, checked before uploading
--}}
<div x-data="chunkitUploader({{ \Illuminate\Support\Js::from(url(config('chunkit.route_prefix'))) }}, {{ \Illuminate\Support\Js::from($limits ?? null) }})"
     @uploader-reset.window="reset()"
     x-effect="$dispatch('uploader-busy', blocking)">
    <label for="{{ $id }}" class="mb-1.5 block text-sm font-medium text-slate-700">{{ $label }}</label>
    <input id="{{ $id }}" type="file" x-ref="picker" accept="{{ $accept }}"
           x-on:change="pick($event)" :disabled="busy"
           class="block w-full text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium hover:file:bg-slate-200">
    <p class="mt-1.5 text-xs text-slate-500" x-show="status === 'idle'">{{ $hint }}</p>

    <div x-show="status !== 'idle'" x-cloak class="mt-2 space-y-1.5">
        <div class="h-2 w-full overflow-hidden rounded-full bg-slate-100">
            <div class="h-full rounded-full transition-all"
                 :class="status === 'error' ? 'bg-red-500' : (status === 'done' ? 'bg-green-500' : 'bg-indigo-600')"
                 :style="`width: ${percent}%`"></div>
        </div>
        <div class="flex items-center justify-between gap-2 text-xs">
            <span :class="status === 'error' ? 'text-red-600' : 'text-slate-600'" x-text="message || (percent + '%')"></span>
            <span class="flex shrink-0 gap-2">
                <button type="button" x-show="status === 'error' && file" @click="start()" class="font-medium text-indigo-600 hover:underline">Retry</button>
                <button type="button" x-show="!busy" @click="reset()" class="font-medium text-slate-500 hover:underline">Remove</button>
            </span>
        </div>
    </div>

    <input type="hidden" name="chunkit_upload_id" :value="status === 'done' ? uploadId : ''">
    @error('chunkit_upload_id')
        <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
    @enderror
</div>

@once
    @push('scripts')
        <script>
            // Resumable uploader for muhpuc40/chunkit. The server is the source of truth: after
            // a refresh or a network error the client asks /status where to continue.
            document.addEventListener('alpine:init', () => {
                Alpine.data('chunkitUploader', (baseUrl, limits = null) => ({
                    uploadId: null,
                    file: null,
                    chunkSize: 0,
                    offset: 0,
                    percent: 0,
                    status: 'idle', // idle | uploading | resuming | processing | done | error
                    message: '',

                    get busy() {
                        return ['uploading', 'resuming', 'processing'].includes(this.status);
                    },

                    // A picked file that is not finished yet must block Save.
                    get blocking() {
                        return this.file !== null && this.status !== 'done';
                    },

                    reset(clearPicker = true) {
                        this.uploadId = null;
                        this.file = null;
                        this.offset = 0;
                        this.percent = 0;
                        this.status = 'idle';
                        this.message = '';
                        if (clearPicker && this.$refs.picker) this.$refs.picker.value = '';
                    },

                    csrf() {
                        return document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                    },

                    storageKey() {
                        return `chunkit:${this.file.name}:${this.file.size}:${this.file.lastModified}`;
                    },

                    pick(event) {
                        const file = event.target.files[0] ?? null;
                        this.reset(false); // keep the picked file visible in the input
                        this.file = file;
                        if (file) this.start();
                    },

                    // Same check as the server (System > File types), before any byte is sent.
                    problem(file) {
                        if (!limits || !file) return null;
                        const parts = file.name.toLowerCase().split('.');
                        const ext = parts.length > 1 ? parts.pop() : '';
                        if (!ext) return 'The file needs an extension, like .pdf or .zip.';
                        if (!(ext in limits)) return `.${ext} files are not allowed.`;
                        if (file.size > limits[ext] * 1024 * 1024) return `The file is larger than ${limits[ext]} MB, the limit for .${ext} files.`;
                        return null;
                    },

                    async api(path, options = {}) {
                        const response = await fetch(baseUrl + path, {
                            ...options,
                            headers: {
                                'X-CSRF-TOKEN': this.csrf(),
                                'Accept': 'application/json',
                                ...(options.body ? { 'Content-Type': 'application/json' } : {}),
                            },
                        });
                        let body = {};
                        try { body = await response.json(); } catch (e) { body = { message: `Unexpected response (HTTP ${response.status}).` }; }
                        return { ok: response.ok, status: response.status, body };
                    },

                    async start() {
                        if (!this.file) return;
                        const problem = this.problem(this.file);
                        if (problem) {
                            this.reset(); // nothing to upload: Save stays usable (e.g. for a link)
                            this.status = 'error';
                            this.message = problem;
                            return;
                        }
                        this.message = '';
                        try {
                            if (!(await this.restoreFromServer())) {
                                await this.initiate();
                            }
                            await this.sendRemainingChunks();
                            await this.complete();
                        } catch (error) {
                            if (error && error.alreadyDone) return;
                            this.status = 'error';
                            this.message = error.message;
                        }
                    },

                    useUpload(uploadId, info) {
                        this.uploadId = uploadId;
                        this.chunkSize = info.chunk_size;
                        this.offset = info.offset;
                        this.percent = Math.floor((this.offset / this.file.size) * 100);
                    },

                    // Same file as an unfinished upload (e.g. after a page refresh)? Continue it.
                    async restoreFromServer() {
                        const saved = localStorage.getItem(this.storageKey());
                        if (!saved) return false;

                        this.status = 'resuming';
                        this.message = 'Checking where the upload stopped…';
                        const { ok, body } = await this.api(`/${saved}/status`);

                        // Already uploaded but not saved yet (e.g. the form failed validation): reuse it.
                        if (ok && body.status === 'completed' && body.total_bytes === this.file.size) {
                            this.uploadId = saved;
                            this.percent = 100;
                            this.status = 'done';
                            this.message = 'Already uploaded. Press Save.';
                            throw { alreadyDone: true };
                        }

                        if (!ok ||!['pending', 'uploading', 'merging'].includes(body.status) || body.total_bytes !== this.file.size) {
                            localStorage.removeItem(this.storageKey());
                            return false;
                        }
                        this.useUpload(saved, body);
                        return true;
                    },

                    async initiate() {
                        this.status = 'uploading';
                        const { ok, status, body } = await this.api('/initiate', {
                            method: 'POST',
                            body: JSON.stringify({
                                original_name: this.file.name,
                                total_bytes: this.file.size,
                                mime_type: this.file.type || null,
                            }),
                        });
                        if (!ok) {
                            const firstError = body.errors ? Object.values(body.errors)[0][0] : null;
                            throw new Error(firstError ?? body.message ?? `Could not start the upload (HTTP ${status}).`);
                        }
                        this.useUpload(body.upload_id, { ...body, offset: 0 });
                        localStorage.setItem(this.storageKey(), body.upload_id);
                    },

                    async sendRemainingChunks() {
                        this.status = 'uploading';
                        this.message = '';
                        let conflicts = 0;

                        while (this.offset < this.file.size) {
                            const index = Math.floor(this.offset / this.chunkSize);
                            const response = await this.sendChunk(index);

                            if (response.status === 200) {
                                conflicts = 0;
                                this.offset = response.offset;
                                this.percent = Math.floor((this.offset / this.file.size) * 100);
                            } else if (response.status === 409) {
                                // The server's Upload-Offset header is its real resume point: continue from there.
                                if (++conflicts > 5 || Number.isNaN(response.offset)) {
                                    throw new Error(response.message ?? 'The server keeps rejecting this piece.');
                                }
                                this.offset = response.offset;
                            } else {
                                throw new Error(response.message ?? `Upload failed (HTTP ${response.status}).`);
                            }
                        }
                    },

                    // XHR (not fetch) so the progress bar moves while each piece is sent.
                    sendChunk(index) {
                        return new Promise((resolve, reject) => {
                            const start = index * this.chunkSize;
                            const form = new FormData();
                            form.append('index', index);
                            form.append('chunk', this.file.slice(start, start + this.chunkSize), 'chunk.bin');

                            const xhr = new XMLHttpRequest();
                            xhr.open('POST', `${baseUrl}/${this.uploadId}/chunk`);
                            xhr.setRequestHeader('X-CSRF-TOKEN', this.csrf());
                            xhr.setRequestHeader('Accept', 'application/json');
                            xhr.setRequestHeader('Upload-Offset', String(start));

                            xhr.upload.onprogress = (event) => {
                                if (event.lengthComputable) {
                                    this.percent = Math.min(99, Math.floor(((start + event.loaded) / this.file.size) * 100));
                                }
                            };
                            xhr.onload = () => {
                                let message;
                                try { message = JSON.parse(xhr.responseText).message; } catch (e) { message = undefined; }
                                resolve({ status: xhr.status, offset: parseInt(xhr.getResponseHeader('Upload-Offset'), 10), message });
                            };
                            xhr.onerror = () => reject(new Error('Connection lost. Press Retry to continue from where it stopped.'));
                            xhr.send(form);
                        });
                    },

                    async complete() {
                        const finish = await this.api(`/${this.uploadId}/complete`, { method: 'POST' });
                        if (!finish.ok) {
                            throw new Error(finish.body.message ?? `Could not finish the upload (HTTP ${finish.status}).`);
                        }

                        // The pieces are joined by a queue worker; wait for it.
                        this.status = 'processing';
                        this.message = 'Joining the pieces… (needs php artisan queue:work running)';

                        for (let attempt = 0; attempt < 400; attempt++) {
                            const { body } = await this.api(`/${this.uploadId}/status`);
                            if (body.status === 'completed') {
                                localStorage.removeItem(this.storageKey());
                                this.percent = 100;
                                this.status = 'done';
                                this.message = 'Uploaded. Press Save.';
                                return;
                            }
                            if (body.status === 'failed') {
                                localStorage.removeItem(this.storageKey());
                                this.uploadId = null;
                                throw new Error(body.failure_reason ?? 'The file was rejected.');
                            }
                            await new Promise((resolve) => setTimeout(resolve, 1500));
                        }
                        throw new Error('The server is taking too long to join the file. Press Retry in a moment.');
                    },
                }));
            });
        </script>
    @endpush
@endonce
