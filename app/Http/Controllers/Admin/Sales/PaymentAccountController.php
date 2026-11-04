<?php

namespace App\Http\Controllers\Admin\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PaymentAccountRequest;
use App\Models\PaymentAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Sales > Accounts: where students send money (bKash/Nagad number, bank account, cash).
 * Students see the active ones, in sort_order, before they submit payment proof.
 *
 * An account that has received payments is never deleted, only hidden: every payment row
 * remembers which of our accounts it went to (payment_list.payment_account_id), and the
 * admin needs that when checking statements.
 */
class PaymentAccountController extends Controller
{
    public function index(): View
    {
        return view('admin.payment-accounts.index', [
            'accounts' => PaymentAccount::withCount('payments')->orderBy('sort_order')->orderBy('id')->get(),
        ]);
    }

    public function store(PaymentAccountRequest $request): RedirectResponse
    {
        PaymentAccount::create($request->account());

        return back()->with('success', 'Account added.');
    }

    public function update(PaymentAccountRequest $request, PaymentAccount $account): RedirectResponse
    {
        $account->update($request->account());

        return back()->with('success', 'Account updated.');
    }

    /** Show / hide for students without deleting anything. */
    public function toggle(PaymentAccount $account): RedirectResponse
    {
        $account->update(['is_active' => ! $account->is_active]);

        return back()->with('success', $account->is_active
            ? 'Account is now shown to students.'
            : 'Account is now hidden from students.');
    }

    public function destroy(PaymentAccount $account): RedirectResponse
    {
        if ($account->payments()->exists()) {
            return back()->with('error', "This account has {$account->payments()->count()} payment(s). Hide it instead, so their history stays.");
        }

        $account->delete();

        return back()->with('success', 'Account deleted.');
    }
}
