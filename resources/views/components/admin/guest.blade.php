{{-- Pages without the sidebar (login ...). <x-admin.guest title="Sign in"> ... </x-admin.guest> --}}
@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-100">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title . ' · ' : '' }}{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-full font-sans text-slate-800 antialiased">
    <div class="flex min-h-screen flex-col items-center justify-center px-4 py-10">
        <div class="mb-6 flex items-center gap-2 text-slate-900">
            <img src="{{ url('logo.svg') }}" alt="{{ config('app.name') }} logo" class="size-10">
            <span class="text-xl font-semibold">{{ config('app.name') }}</span>
        </div>

        <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-900/5 sm:p-8">
            {{ $slot }}
        </div>

        <p class="mt-6 text-xs text-slate-500">&copy; {{ date('Y') }} {{ config('app.name') }}</p>
    </div>
</body>

</html>