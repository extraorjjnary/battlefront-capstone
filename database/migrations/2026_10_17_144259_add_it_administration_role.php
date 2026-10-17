<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $roleId = DB::table('administration_roles')->insertGetId([
            'code' => 'it',
            'name' => 'IT Administrator',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['users.manage', 'audit.view'] as $permission) {
            DB::table('administration_role_permissions')->insert([
                'administration_role_id' => $roleId,
                'administration_permission_id' => DB::table('administration_permissions')->where('code', $permission)->value('id'),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $roleId = DB::table('administration_roles')->where('code', 'it')->value('id');
        if ($roleId === null) {
            return;
        }
        if (DB::table('users')->where('administration_role_id', $roleId)->exists()) {
            throw new RuntimeException('IT accounts must be reassigned before rolling back this role.');
        }

        DB::table('administration_role_permissions')->where('administration_role_id', $roleId)->delete();
        DB::table('administration_roles')->where('id', $roleId)->delete();
    }
};
