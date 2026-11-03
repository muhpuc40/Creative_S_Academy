<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\Affiliate;
use App\Models\AffiliateProfile;
use App\Models\Instructor;
use App\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Test accounts for local development (all fake). Password for all: Password@123
 *
 *   admin@csa.com
 *   instructor1..5@csa.com
 *   student1..24@csa.com
 *     student1  = normal, verified            student3 = suspended
 *     student4  = NOT verified yet            student24 = deleted (soft)
 *   affiliate1..7@csa.com
 *     affiliate1,2 = approved (ref link works)   affiliate3 = pending
 *     affiliate4   = pending + NOT verified      affiliate5 = rejected
 *     affiliate6   = program suspended           affiliate7 = ACCOUNT suspended (cannot log in)
 */
class DemoUsersSeeder extends Seeder
{
    public function run(): void
    {
        Model::unguarded(function () {
            $password = Hash::make('Password@123');   // hashed once, so seeding stays fast
            $verified = ['password' => $password, 'verified_at' => now(), 'verified_channel' => 'email'];

            $superAdmin = Admin::firstOrCreate(['email' => 'admin@csa.com'], $verified + [
                'name' => 'Super Admin',
                'phone' => '01700000000',
                'status' => 'active',
            ]);
            Admin::firstOrCreate(['email' => 'admin1@csa.com'], $verified + [
                'name' => 'Admin 1',
                'phone' => '01700000002',
                'status' => 'active',
            ]);
            Admin::firstOrCreate(['email' => 'admin2@csa.com'], $verified + [
                'name' => 'Admin 2',
                'phone' => '01700000003',
                'status' => 'active',
            ]);
            $instructors = [
                [
                    'Nusrat Jahan',
                    'Senior Web Instructor',
                    'Full stack developer with 8 years of teaching experience.'
                ],
                [
                    'Tanvir Ahmed',
                    'Mobile App Instructor',
                    'Flutter and Android trainer.'
                ],
                [
                    'Farhana Akter',
                    'Data Science Instructor',
                    'Python, statistics and machine learning.'
                ],
                [
                    'Imran Hossain',
                    'Design Instructor',
                    'UI/UX and brand identity designer.'
                ],
                [
                    'Rakib Hasan',
                    'Marketing Instructor',
                    'SEO and digital marketing consultant.'
                ],
            ];
            foreach ($instructors as $i => [$name, $designation, $bio]) {
                $n = $i + 1;
                Instructor::firstOrCreate(['email' => "instructor{$n}@csa.com"], $verified + [
                    'name' => $name,
                    'phone' => '017000001' . sprintf('%02d', $n),
                    'designation' => $designation,
                    'bio' => $bio,
                    'status' => 'active',
                ]);
            }

            $names = [
                'Rahim Uddin',
                'Karim Hossain',
                'Sumaiya Akter',
                'Jahid Hasan',
                'Mehedi Hasan',
                'Tasnim Jahan',
                'Sabbir Ahmed',
                'Nadia Islam',
                'Arif Rahman',
                'Mim Chowdhury',
                'Shakil Mahmud',
                'Rima Sultana',
                'Faisal Karim',
                'Lamia Khatun',
                'Naim Hossain',
                'Puja Das',
                'Rubel Mia',
                'Sharmin Akter',
                'Zahid Hasan',
                'Tamanna Rahman',
                'Ovi Khan',
                'Maliha Noor',
                'Sohel Rana',
                'Deleted Student',
            ];
            foreach ($names as $i => $name) {
                $n = $i + 1;
                $student = Student::firstOrCreate(['email' => "student{$n}@csa.com"], [
                    'name' => $name,
                    'phone' => '017000002' . sprintf('%02d', $n),
                    'password' => $password,
                    'verified_at' => $n === 4 ? null : now(),
                    'verified_channel' => $n === 4 ? null : 'email',
                    'roll' => $n <= 12 ? sprintf('SF-2026-%04d', $n) : null,   // roll is given after the first enrollment
                    'gender' => $n % 2 ? 'male' : 'female',
                    'status' => $n === 3 ? 'suspended' : 'active',
                    'address' => ['Dhaka', 'Chattogram', 'Rajshahi', 'Sylhet', 'Khulna'][$n % 5],
                ]);

                if ($n === 24) {
                    $student->delete();
                }
            }

            // name, program status (affiliate_profiles), account status (affiliates), email verified?
            $affiliates = [
                ['Sadia Rahman', 'approved', 'active', true],
                ['Jubayer Ahmed', 'approved', 'active', true],
                ['Mahfuz Alam', 'pending', 'active', true],
                ['Nilufa Yasmin', 'pending', 'active', false],
                ['Rezaul Karim', 'rejected', 'active', true],
                ['Tanjila Islam', 'suspended', 'active', true],
                ['Habib Ullah', 'approved', 'suspended', true],
            ];
            foreach ($affiliates as $i => [$name, $program, $account, $isVerified]) {
                $n = $i + 1;
                $affiliate = Affiliate::firstOrCreate(['email' => "affiliate{$n}@csa.com"], [
                    'name' => $name,
                    'phone' => '017000003' . sprintf('%02d', $n),
                    'password' => $password,
                    'verified_at' => $isVerified ? now() : null,
                    'verified_channel' => $isVerified ? 'email' : null,
                    'status' => $account,
                ]);

                // pending = nobody has decided yet; every other status was decided by the super admin
                $decided = $program !== 'pending';

                AffiliateProfile::firstOrCreate(['affiliate_id' => $affiliate->id], [
                    'ref_code' => strtoupper(strtok($name, ' ')) . sprintf('%03d', 100 + $n),   // SADIA101
                    'status' => $program,
                    'approved_by' => $decided ? $superAdmin->id : null,
                    'approved_at' => $decided ? now() : null,
                    'payout_method' => $program === 'approved' ? 'mfs' : null,
                    'payout_provider' => $program === 'approved' ? 'bkash' : null,
                    'payout_account' => $program === 'approved' ? $affiliate->phone : null,
                    'payout_account_name' => $program === 'approved' ? $name : null,
                ]);
            }
        });
    }
}