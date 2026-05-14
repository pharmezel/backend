<?php

namespace Database\Seeders;

use App\Models\ReferralLink;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $superadmin = User::updateOrCreate(
            ['email' => 'admin@pharmicare.com'],
            [
                'first_name' => 'Pharmicare',
                'last_name' => 'Admin',
                'password' => 'pharmicare',
                'role' => 'superadmin',
                'contact_number' => '09170000000',
                'date_of_birth' => null,
                'referral_code' => 'PHARMICARE',
                'referrer_code_used' => null,
                'points' => 0,
                'shipping_address' => null,
                'date_registered' => now(),
            ]
        );

        $admin = User::updateOrCreate(
            ['email' => 'admin@mercury.demo'],
            [
                'first_name' => 'Mercury',
                'last_name' => 'Pharmacy',
                'password' => 'admin',
                'role' => 'admin',
                'contact_number' => '09170000001',
                'date_of_birth' => '1990-06-15',
                'referral_code' => 'MERCURYAD',
                'referrer_code_used' => 'PHARMICARE',
                'points' => 120,
                'shipping_address' => '123 Demo St, Quezon City',
                'date_registered' => now(),
            ]
        );

        ReferralLink::updateOrCreate(
            ['referred_id' => $admin->user_id],
            [
                'referrer_id' => $superadmin->user_id,
                'status' => 'active',
            ]
        );

        $buyer = User::updateOrCreate(
            ['email' => 'buyer@demo.pharmezel.com'],
            [
                'first_name' => 'Juan',
                'last_name' => 'Dela Cruz',
                'password' => 'buyer',
                'role' => 'buyer',
                'contact_number' => '09170000002',
                'date_of_birth' => '1995-03-20',
                'referral_code' => null,
                'referrer_code_used' => 'MERCURYAD',
                'points' => 500,
                'shipping_address' => '',
                'date_registered' => now(),
            ]
        );

        ReferralLink::updateOrCreate(
            ['referred_id' => $buyer->user_id],
            [
                'referrer_id' => $admin->user_id,
                'status' => 'active',
            ]
        );
    }
}
