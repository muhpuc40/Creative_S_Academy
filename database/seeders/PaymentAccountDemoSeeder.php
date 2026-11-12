<?php

namespace Database\Seeders;

use App\Models\PaymentAccount;
use Illuminate\Database\Seeder;

/** A couple of demo "where to pay" accounts, so the payment screens have something to show. */
class PaymentAccountDemoSeeder extends Seeder
{
    public function run(): void
    {
        PaymentAccount::firstOrCreate(
            ['method' => 'mfs', 'provider' => 'bKash', 'account_no' => '01700000000'],
            ['account_name' => 'CreativeSacademy', 'instructions' => 'Send Money Only', 'sort_order' => 1, 'is_active' => true],
        );

        PaymentAccount::firstOrCreate(
            ['method' => 'bank', 'provider' => 'City Bank', 'account_no' => '1234567890123'],
            ['account_name' => 'CreativeSacademy Education Ltd.', 'branch' => 'Gulshan Branch', 'instructions' => 'BEFTN or NPSB', 'sort_order' => 2, 'is_active' => true],
        );
    }
}
