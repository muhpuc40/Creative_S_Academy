{{-- <x-admin.order-status :status="$order->status" />  pending / partially paid / paid / expired / cancelled --}}
@props(['status'])
@php
    $value = $status instanceof \BackedEnum ? $status->value : (string) $status;
    [$color, $label] = match ($value) {
        'pending' => ['amber', 'Waiting for payment'],
        'partially_paid' => ['blue', 'Partly paid'],
        'paid' => ['green', 'Paid'],
        'expired' => ['gray', 'Expired'],
        'cancelled' => ['red', 'Cancelled'],
        default => ['gray', ucfirst($value)],
    };
@endphp
<x-admin.badge :color="$color" {{ $attributes->merge(['class' => 'whitespace-nowrap']) }}>{{ $label }}</x-admin.badge>
