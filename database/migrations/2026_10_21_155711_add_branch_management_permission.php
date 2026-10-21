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
        DB::transaction(function (): void {
            DB::table('administration_permissions')->insertOrIgnore([
                'code' => 'branches.manage', 'name' => 'branches.manage',
            ]);
            $permissionId = DB::table('administration_permissions')->where('code', 'branches.manage')->value('id');

            foreach (['manager', 'owner'] as $roleCode) {
                DB::table('administration_role_permissions')->insertOrIgnore([
                    'administration_role_id' => DB::table('administration_roles')->where('code', $roleCode)->value('id'),
                    'administration_permission_id' => $permissionId,
                ]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::transaction(function (): void {
            $permissionId = DB::table('administration_permissions')->where('code', 'branches.manage')->value('id');

            DB::table('administration_role_permissions')->where('administration_permission_id', $permissionId)->delete();
            DB::table('administration_permissions')->where('id', $permissionId)->delete();
        });
    }
};
