<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            BranchSeeder::class,
            DevelopmentChatbotKnowledgeSeeder::class,
        ]);

        User::query()->updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'email_verified_at' => Carbon::now(),
                'password' => 'password',
                'role' => UserRole::Customer->value,
            ],
        );

        $administrator = User::query()->firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'IT Administrator',
                'email_verified_at' => Carbon::now(),
                'password' => 'password',
            ],
        );
        $administrator->role = UserRole::Administrator;
        $administrator->administration_role_id = DB::table('administration_roles')->where('code', 'it')->value('id');
        $administrator->email_verified_at ??= Carbon::now();
        $administrator->save();
    }
}
