<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\VoucherStatus;
use App\Models\Order;
use App\Models\PaymentVoucher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The ONE place an unpaid order expires. Scheduled every 5 minutes (routes/console.php).
 *
 * A pending order whose payment window (Order::PAYMENT_WINDOW_HOURS after checkout) has
 * passed becomes "expired", which frees its batch seats and gives back its voucher - unless
 * a payment proof for it is still waiting for admin review: that student clearly paid, so a
 * slow admin queue must never cancel the order under them.
 *
 * Partially paid / paid orders are never touched: once money is verified an order is kept.
 */
class ExpireUnpaidOrders extends Command
{
    protected $signature = 'orders:expire';

    protected $description = 'Expire unpaid orders whose payment window has passed (frees seats and vouchers)';

    public function handle(): int
    {
        $expired = 0;

        Order::where('status', OrderStatus::Pending)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->pluck('id')
            ->each(function (int $orderId) use (&$expired) {
                if ($this->expire($orderId)) {
                    $expired++;
                }
            });

        $this->info("Expired {$expired} unpaid order(s).");

        return self::SUCCESS;
    }

    /**
     * Re-checks the order under a row lock, so a student submitting a payment at this exact
     * moment (PaymentService::submit() locks the same row) and this command can't both win.
     */
    private function expire(int $orderId): bool
    {
        return DB::transaction(function () use ($orderId) {
            /** @var Order|null $order */
            $order = Order::lockForUpdate()->find($orderId);

            $stillStale = $order
                && $order->status === OrderStatus::Pending
                && $order->expires_at !== null
                && $order->expires_at->isPast()
                && ! $order->payments()->where('status', PaymentStatus::Pending)->exists();

            if (! $stillStale) {
                return false;
            }

            $order->update(['status' => OrderStatus::Expired]);

            if ($order->payment_voucher_id) {
                PaymentVoucher::where('id', $order->payment_voucher_id)
                    ->where('status', VoucherStatus::Used)
                    ->update(['status' => VoucherStatus::Active, 'used_at' => null]);
            }

            return true;
        });
    }
}
