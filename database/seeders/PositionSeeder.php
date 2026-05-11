<?php

namespace Database\Seeders;

use App\Models\Position;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class PositionSeeder extends Seeder
{
    public function run(): void
    {
        $positions = [
            ['title' => 'Senior BDE', 'description' => 'Senior Business Development Executive'],
            ['title' => 'BDE', 'description' => 'Business Development Executive'],
            ['title' => 'Intern BDE', 'description' => 'Intern Business Development Executive'],
            ['title' => 'Junior Developer', 'description' => 'Junior Developer'],
            ['title' => 'Developer', 'description' => 'Developer'],
            ['title' => 'Senior Developer', 'description' => 'Senior Developer'],
            ['title' => 'Junior Software Engineer', 'description' => 'Junior Software Engineer'],
            ['title' => 'Software Engineer', 'description' => 'Software Engineer'],
            ['title' => 'Senior Software Engineer', 'description' => 'Senior Software Engineer'],
            ['title' => 'CEO', 'description' => 'Chief Executive Officer'],
            ['title' => 'CTO', 'description' => 'Chief Technology Officer'],
        ];

        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            foreach ($positions as $pos) {
                Position::withoutGlobalScopes()->firstOrCreate(
                    ['tenant_id' => $tenant->tenant_id, 'title' => $pos['title']],
                    ['description' => $pos['description'], 'is_active' => true]
                );
            }
        }
    }
}
