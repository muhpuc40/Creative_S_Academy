<?php

namespace App\Http\Controllers\Admin\Sales;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Sales > Orders: every checkout, its courses, dues and the payments sent for it.
 * Read-only: money is handled under Sales > Payments (verify / reject), and orders expire on
 * their own (orders:expire). Opened by order number (/orders/CSA-2611-0001), never by id.
 */
class OrderController extends Controller
{
    /** GET /orders - search by order no, student name / email / phone; filter by status. */
    public function index(Request $request): View
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $request->input('status');

        $orders = Order::query()
            ->with('student:id,name,email,phone')
            ->withCount(['payments as payments_waiting' => fn ($q) => $q->where('status', 'pending')])
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.trim($request->input('q')).'%';
                $q->where(fn ($w) => $w->where('order_no', 'like', $term)
                    ->orWhereHas('student', fn ($s) => $s->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('phone', 'like', $term)));
            })
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        // how many orders are in each status (the cards above the list)
        $counts = Order::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('admin.orders.index', [
            'orders' => $orders,
            'status' => $status,
            'counts' => $counts,
        ]);
    }

    /** GET /orders/{order_no} - the courses, every due, the payments, discounts and who referred the student. */
    public function show(Order $order): View
    {
        $order->load([
            'student', 'combo:id,title', 'voucher:id,code,title', 'attribution.affiliate:id,name',
            'items.course:id,title,slug', 'items.batch:id,name,start_date', 'items.installments', 'items.enrollment',
            'payments' => fn ($q) => $q->latest('id'),
            'payments.installments',
        ]);

        return view('admin.orders.show', ['order' => $order]);
    }
}
