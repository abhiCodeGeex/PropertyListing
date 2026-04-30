<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class RolesAndSuperAdminSeeder extends Seeder
{
    public function run()
    {
        $roles = ['super-admin', 'owner', 'tenant', 'agent', 'property_manager'];
        foreach ($roles as $r) {
            Role::firstOrCreate([
                'name' => $r,
                'guard_name' => 'web',
            ]);
        }

        // Create super admin if not exists
        $email = 'superadmin@yopmail.com';
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Super Admin',
                'username' => 'superadmin',
                'password' => Hash::make('password@123'),
                'email_verified_at' => now(),
            ]
        );

        $user->syncRoles(['super-admin']);
    }
}
