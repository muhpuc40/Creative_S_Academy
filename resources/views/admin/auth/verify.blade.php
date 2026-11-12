<x-admin.guest title="Verify">
    <div x-data="{
            wait: {{ (int) $wait }},
            init() {
                if (this.wait <= 0) return;
                const t = setInterval(() => { if (--this.wait <= 0) { this.wait = 0; clearInterval(t); } }, 1000);
            },
         }">
    <h1 class="text-lg font-semibold text-slate-900">Verify it's you</h1>

    @if ($revokeDevice)
        <x-admin.alert type="warning" class="mt-4">
            <strong>{{ $revokeDevice->device_name ?? 'Another device' }}</strong> will be signed out after you verify.
        </x-admin.alert>
    @endif

    @if (session('status'))
        <x-admin.alert type="success" class="mt-4">{{ session('status') }}</x-admin.alert>
    @endif

    @if (session('debug_code'))
        <x-admin.alert type="info" class="mt-4">Local test code: <strong class="font-mono">{{ session('debug_code') }}</strong></x-admin.alert>
    @endif

    @if ($sent)
        {{-- step 3: enter the code --}}
        <p class="mt-1 text-sm text-slate-500">Enter the 6-digit code we sent to <strong>{{ $sentTo }}</strong>.</p>

        <form method="POST" action="{{ route('admin.login.verify.submit') }}" class="mt-6 space-y-5">
            @csrf
            <x-admin.input name="code" label="Verification code" inputmode="numeric" maxlength="6"
                           autocomplete="one-time-code" autofocus required class="text-center font-mono text-lg tracking-widest" />
            <x-admin.button type="submit" class="w-full">Verify and sign in</x-admin.button>
        </form>

        <div class="mt-4 flex items-center justify-between text-sm">
            <form method="POST" action="{{ route('admin.login.send-code') }}">
                @csrf
                <input type="hidden" name="channel" value="{{ $channel }}">
                <button type="submit" class="text-indigo-600 hover:underline disabled:text-slate-400 disabled:no-underline"
                        @disabled($wait > 0) :disabled="wait > 0">
                    Resend code <span x-show="wait > 0">(wait <span x-text="wait">{{ $wait }}</span>s)</span>
                </button>
            </form>
            <a href="{{ route('admin.login.verify', ['change' => 1]) }}" class="text-slate-500 hover:underline">Use another method</a>
        </div>
    @else
        {{-- step 2: choose where to send the code --}}
        <p class="mt-1 text-sm text-slate-500">Choose where to send your verification code.</p>

        <form method="POST" action="{{ route('admin.login.send-code') }}" class="mt-6 space-y-3">
            @csrf
            @foreach ($options as $i => $option)
                <label class="flex cursor-pointer items-center gap-3 rounded-lg p-3 text-sm ring-1 ring-inset ring-slate-300 has-checked:bg-indigo-50 has-checked:ring-indigo-600">
                    <input type="radio" name="channel" value="{{ $option['channel'] }}" class="size-4 text-indigo-600" @checked($i === 0) required>
                    <span>
                        <span class="block font-medium text-slate-900">{{ $option['channel'] === 'sms' ? 'Text message (SMS)' : 'Email' }}</span>
                        <span class="block font-mono text-xs text-slate-500">{{ $option['masked'] }}</span>
                    </span>
                </label>
            @endforeach
            {{-- the cooldown message counts down live; other channel errors show as-is --}}
            @error('channel')
                @unless (str_starts_with($message, 'Please wait'))
                    <p class="text-xs text-red-600">{{ $message }}</p>
                @endunless
            @enderror
            <p x-show="wait > 0" @if ($wait <= 0) style="display: none" @endif class="text-xs text-red-600">
                Please wait <span x-text="wait">{{ $wait }}</span> seconds before requesting a new code.
            </p>

            <x-admin.button type="submit" class="mt-2 w-full" x-bind:disabled="wait > 0">Send code</x-admin.button>
        </form>
    @endif

    <form method="POST" action="{{ route('admin.login.cancel') }}" class="mt-6 border-t border-slate-100 pt-4 text-center">
        @csrf
        <button type="submit" class="text-sm text-slate-500 hover:underline">Start over</button>
    </form>
    </div>
</x-admin.guest>
