<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(UserTypeDeviceLimitSeeder::class);


        if (app()->environment('local')) {
            $this->call(DemoUsersSeeder::class);
            $this->call(CourseDemoSeeder::class);
            $this->call(BatchDemoSeeder::class);
            $this->call(PaymentAccountDemoSeeder::class);
        }
    }
}