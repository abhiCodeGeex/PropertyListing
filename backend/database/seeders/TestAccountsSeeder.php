<?php

namespace Database\Seeders;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TestAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $password = 'password@123';

        $accounts = [
            [
                'role' => 'super-admin',
                'name' => 'Super Admin',
                'username' => 'superadmin',
                'email' => 'superadmin@yopmail.com',
                'dob' => '1990-01-15',
                'current_address' => 'Super Admin Address, Ahmedabad',
                'native_address' => 'Super Admin Native Address, Ahmedabad',
                'aadhar' => '111122223333',
                'aadhar_text' => 'Super Admin',
                'pan' => 'ABCDE1234F',
                'marital_status' => 'single',
                'gender' => 'male',
                'phone' => '9876543210',
            ],
            [
                'role' => 'owner',
                'name' => 'Owner User',
                'username' => 'owneruser',
                'email' => 'owner@yopmail.com',
                'dob' => '1991-02-20',
                'current_address' => 'Owner Address, Surat',
                'native_address' => 'Owner Native Address, Vadodara',
                'aadhar' => '222233334444',
                'aadhar_text' => 'Owner User',
                'pan' => 'BCDEA2345G',
                'marital_status' => 'married',
                'gender' => 'female',
                'phone' => '9876543211',
            ],
            [
                'role' => 'tenant',
                'name' => 'Tenant User',
                'username' => 'tenantuser',
                'email' => 'tenant@yopmail.com',
                'dob' => '1994-03-10',
                'current_address' => 'Tenant Address, Pune',
                'native_address' => 'Tenant Native Address, Nashik',
                'aadhar' => '333344445555',
                'aadhar_text' => 'Tenant User',
                'pan' => 'CDEAB3456H',
                'marital_status' => 'single',
                'gender' => 'male',
                'phone' => '9876543212',
            ],
            [
                'role' => 'agent',
                'name' => 'Agent User',
                'username' => 'agentuser',
                'email' => 'agent@yopmail.com',
                'dob' => '1992-04-18',
                'current_address' => 'Agent Address, Mumbai',
                'native_address' => 'Agent Native Address, Nagpur',
                'aadhar' => '444455556666',
                'aadhar_text' => 'Agent User',
                'pan' => 'DEABC4567J',
                'marital_status' => 'single',
                'gender' => 'other',
                'phone' => '9876543213',
            ],
            [
                'role' => 'property_manager',
                'name' => 'Property Manager',
                'username' => 'manageruser',
                'email' => 'manager@yopmail.com',
                'dob' => '1993-05-25',
                'current_address' => 'Manager Address, Bengaluru',
                'native_address' => 'Manager Native Address, Mysuru',
                'aadhar' => '555566667777',
                'aadhar_text' => 'Property Manager',
                'pan' => 'EABCD5678K',
                'marital_status' => 'married',
                'gender' => 'female',
                'phone' => '9876543214',
            ],
        ];

        foreach ($accounts as $account) {
            $user = User::updateOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'username' => $account['username'],
                    'password' => Hash::make($password),
                    'email_verified_at' => now(),
                ]
            );

            $user->syncRoles([$account['role']]);

            Profile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'first_name' => explode(' ', $account['name'])[0],
                    'last_name' => trim(str_replace(explode(' ', $account['name'])[0], '', $account['name'])) ?: 'User',
                    'dob' => $account['dob'],
                    'current_address' => $account['current_address'],
                    'native_address' => $account['native_address'],
                    'aadhar' => $account['aadhar'],
                    'aadhar_text' => $account['aadhar_text'],
                    'pan' => $account['pan'],
                    'marital_status' => $account['marital_status'],
                    'gender' => $account['gender'],
                    'phone' => $account['phone'],
                ]
            );
        }
    }
}
