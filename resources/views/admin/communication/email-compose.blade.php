@push('styles')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-lite.min.css">
    <style>
        .note-editor.note-frame {
            border-color: #cbd5e1;
            border-radius: 0.5rem;
            overflow: hidden;
        }

        .note-editor .note-toolbar {
            background: #f8fafc;
            border-bottom-color: #e2e8f0;
        }

        .note-editor .note-editable {
            color: #0f172a;
            min-height: 260px;
        }

        .note-editor.note-frame.fullscreen {
            position: fixed !important;
            inset: 0 !important;
            z-index: 9999 !important;
            width: 100vw !important;
            height: 100vh !important;
            border: 0 !important;
            border-radius: 0 !important;
            background: #fff !important;
            overflow: hidden !important;
        }

        .note-editor.note-frame.fullscreen .note-editing-area {
            height: calc(100vh - 47px) !important;
        }

        .note-editor.note-frame.fullscreen .note-editable {
            height: 100% !important;
            max-height: none !important;
            min-height: 0 !important;
            overflow-y: auto !important;
        }

        body.sf-summernote-fullscreen {
            overflow: hidden !important;
        }
    </style>
@endpush

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-lite.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            $('#email_body').summernote({
                height: 260,
                placeholder: 'Write your email here...',
                fontNames: ['Arial', 'Arial Black', 'Comic Sans MS', 'Courier New', 'Georgia', 'Helvetica', 'Impact', 'Tahoma', 'Times New Roman', 'Trebuchet MS', 'Verdana'],
                fontSizes: ['8', '9', '10', '11', '12', '14', '16', '18', '20', '24', '28', '32', '36', '48'],
                toolbar: [
                    ['style', ['style']],
                    ['font', ['bold', 'italic', 'underline', 'strikethrough', 'superscript', 'subscript', 'clear']],
                    ['fontname', ['fontname']],
                    ['fontsize', ['fontsize']],
                    ['color', ['color']],
                    ['para', ['ul', 'ol', 'paragraph', 'height']],
                    ['table', ['table']],
                    ['insert', ['link', 'picture', 'video', 'hr']],
                    ['view', ['fullscreen', 'codeview', 'help']]
                ],
                callbacks: {
                    onImageUpload: function (files) {
                        Array.from(files).forEach(uploadEmailImage);
                    },
                    onFullscreen: function () {
                        const fullscreen = $('#email_body').next('.note-editor').hasClass('fullscreen');
                        document.body.classList.toggle('sf-summernote-fullscreen', fullscreen);
                    }
                }
            });

            function uploadEmailImage(file) {
                const formData = new FormData();
                formData.append('image', file);

                fetch('{{ route('admin.communication.email.image') }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json'
                    },
                    body: formData
                })
                    .then(response => response.ok ? response.json() : response.json().then(error => Promise.reject(error)))
                    .then(data => $('#email_body').summernote('insertImage', data.url))
                    .catch(() => window.alert('The image could not be uploaded. Please use an image smaller than 2 MB.'));
            }
        });
    </script>
@endpush

<x-admin.layout title="Email composer">
    <x-admin.page-header title="Email composer"
        description="Send a formatted email to selected students, instructors, affiliates or custom addresses." />

    <form method="POST" enctype="multipart/form-data" action="{{ route('admin.communication.email.send') }}"
        class="space-y-6" x-data="{ sending: false }" @submit="sending = true">
        @csrf

        <div class="grid gap-6 lg:grid-cols-2">
            <x-admin.card title="Recipients">
                <div x-data="{ recipientType: @js(old('recipient_type', 'student')) }" class="space-y-5">
                    <x-admin.select name="recipient_type" label="User type" x-model="recipientType" required>
                        <option value="student">Students</option>
                        <option value="instructor">Instructors</option>
                        <option value="affiliate">Affiliates</option>
                    </x-admin.select>

                    @foreach ($recipients as $type => $users)
                        <div x-show="recipientType === '{{ $type }}'" x-cloak>
                            <label for="{{ $type }}_ids" class="mb-1.5 block text-sm font-medium text-slate-700">
                                Select {{ \Illuminate\Support\Str::plural(ucfirst($type)) }}
                            </label>
                            <select id="{{ $type }}_ids" name="user_ids[]" multiple size="8"
                                :disabled="recipientType !== '{{ $type }}'"
                                class="block w-full rounded-lg border-0 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600">
                                @foreach ($users as $user)
                                    <option value="{{ $user->id }}" @selected(in_array($user->id, (array) old('user_ids', [])))>
                                        {{ $user->name }} — {{ $user->email }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="mt-1.5 text-xs text-slate-500">Hold Ctrl (Windows) or Command (Mac) to select
                                multiple.</p>
                        </div>
                    @endforeach

                    @error('user_ids')
                        <p class="text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </x-admin.card>

            <x-admin.card title="Custom recipients">
                <label for="custom_emails" class="mb-1.5 block text-sm font-medium text-slate-700">Email
                    addresses</label>
                <textarea id="custom_emails" name="custom_emails" rows="8"
                    placeholder="one@example.com, two@example.com&#10;or one address per line"
                    class="block w-full rounded-lg border-0 px-3 py-2 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600">{{ old('custom_emails') }}</textarea>
                <p class="mt-1.5 text-xs text-slate-500">Separate multiple addresses with commas, semicolons, or new
                    lines.</p>
                @error('custom_emails')
                    <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </x-admin.card>
        </div>

        <x-admin.card title="Message">
            <div class="space-y-5">
                <x-admin.input name="subject" label="Subject" :value="old('subject')"
                    placeholder="Important update from CreativeSacademy" required />

                <div>
                    <label for="attachment" class="mb-1.5 block text-sm font-medium text-slate-700">Attachment
                        (optional)</label>
                    <input id="attachment" name="attachment" type="file"
                        accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.png,.jpg,.jpeg,.txt"
                        class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200">
                    <p class="mt-1.5 text-xs text-slate-500">Maximum 10 MB. PDF, Office files, ZIP, images, and text
                        files are supported.</p>
                    @error('attachment')
                        <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="email_body" class="mb-1.5 block text-sm font-medium text-slate-700">Message</label>
                    <textarea id="email_body" name="body">{{ old('body') }}</textarea>
                    @error('body')
                        <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </x-admin.card>

        <div class="flex justify-end">
            <x-admin.button type="submit" x-bind:disabled="sending">
                <span x-show="!sending" class="inline-flex items-center gap-2">
                    <x-admin.icon name="send" class="size-4" /> Send email
                </span>
                <span x-show="sending" x-cloak class="inline-flex items-center gap-2">
                    <span class="size-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                    Sending...
                </span>
            </x-admin.button>
        </div>
    </form>
</x-admin.layout>