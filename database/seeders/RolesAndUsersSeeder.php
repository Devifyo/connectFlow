<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\Tenant;
use App\Models\User;
use Spatie\Permission\Models\Role;

class RolesAndUsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 0. Create Spatie Roles
        $superAdminRole = Role::create(['name' => 'SuperAdmin']);
        $tenantAdminRole = Role::create(['name' => 'TenantAdmin']);
        $bidderRole = Role::create(['name' => 'Bidder']);

        // 1. Create a Super Admin
        $superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@connectflow.com',
            'password' => Hash::make('password'),
            'is_active' => true,
            'tenant_id' => null, // Super Admins don't belong to a specific tenant
        ]);
        $superAdmin->assignRole($superAdminRole);

        // 2. Create an initial Tenant (Agency)
        $tenant1 = Tenant::create([
            'company_name' => 'Alpha Agency',
            'subscription_plan' => 'Pro',
            'subscription_status' => 'active',
        ]);

        // 3. Create a Tenant Admin for Alpha Agency
        $tenantAdmin1 = User::create([
            'name' => 'Tenant Admin (Alpha)',
            'email' => 'admin@alphaagency.com',
            'password' => Hash::make('password'),
            'is_active' => true,
            'tenant_id' => $tenant1->tenant_id,
        ]);
        $tenantAdmin1->assignRole($tenantAdminRole);

        // 4. Create a Bidder for Alpha Agency
        $bidder1 = User::create([
            'name' => 'John Doe (Bidder)',
            'email' => 'johndoe@alphaagency.com',
            'password' => Hash::make('password'),
            'is_active' => true,
            'tenant_id' => $tenant1->tenant_id,
        ]);
        $bidder1->assignRole($bidderRole);
        
        // 5. Create a second Tenant to ensure multi-tenancy testing
        $tenant2 = Tenant::create([
            'company_name' => 'Beta Freelance Co.',
            'subscription_plan' => 'Starter',
            'subscription_status' => 'active',
        ]);

        $tenantAdmin2 = User::create([
            'name' => 'Tenant Admin (Beta)',
            'email' => 'admin@betafreelance.com',
            'password' => Hash::make('password'),
            'is_active' => true,
            'tenant_id' => $tenant2->tenant_id,
        ]);
        $tenantAdmin2->assignRole($tenantAdminRole);
        
        $bidder2 = User::create([
            'name' => 'Jane Smith (Bidder)',
            'email' => 'janesmith@betafreelance.com',
            'password' => Hash::make('password'),
            'is_active' => true,
            'tenant_id' => $tenant2->tenant_id,
        ]);
        $bidder2->assignRole($bidderRole);
    }
}
