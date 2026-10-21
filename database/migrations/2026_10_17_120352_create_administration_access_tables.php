<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('administration_roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 80);
            $table->timestamps();
        });
        Schema::create('administration_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name', 120);
        });
        Schema::create('administration_role_permissions', function (Blueprint $table) {
            $table->foreignId('administration_role_id')->constrained()->restrictOnDelete();
            $table->foreignId('administration_permission_id')
                ->constrained(indexName: 'admin_role_permission_fk')
                ->restrictOnDelete();
            $table->primary(['administration_role_id', 'administration_permission_id']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('administration_role_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(false);
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 100);
            $table->string('subject_type', 100);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('correlation_id', 36)->nullable();
            $table->text('reason')->nullable();
            $table->json('changes')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id']);
        });

        foreach (['staff' => 'Staff', 'manager' => 'Manager', 'owner' => 'Owner'] as $code => $name) {
            DB::table('administration_roles')->insert(['code' => $code, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }

        $permissions = [
            'orders.view', 'orders.process', 'orders.assign', 'inventory.view',
            'inventory.request', 'inventory.approve', 'inventory.reorder',
            'products.manage', 'products.price', 'catalog.delete',
            'customers.view', 'reports.view', 'reports.export', 'forecast.manage',
            'returns.request', 'returns.approve', 'returns.record_payment', 'returns.dispatch',
            'suppliers.manage', 'staff_reports.view', 'audit.view', 'audit.export',
            'users.manage', 'permissions.manage',
        ];

        foreach ($permissions as $code) {
            DB::table('administration_permissions')->insert(['code' => $code, 'name' => $code]);
        }

        $staff = [
            'orders.view', 'orders.process', 'inventory.view', 'inventory.request',
            'returns.request', 'returns.record_payment', 'returns.dispatch',
        ];
        $manager = [...$staff, 'orders.assign', 'inventory.approve', 'inventory.reorder',
            'products.manage', 'customers.view', 'reports.view', 'forecast.manage',
            'returns.approve', 'suppliers.manage', 'staff_reports.view', 'audit.view'];
        $owner = $permissions;

        foreach (['staff' => $staff, 'manager' => $manager, 'owner' => $owner] as $roleCode => $codes) {
            $roleId = DB::table('administration_roles')->where('code', $roleCode)->value('id');
            foreach ($codes as $code) {
                DB::table('administration_role_permissions')->insert([
                    'administration_role_id' => $roleId,
                    'administration_permission_id' => DB::table('administration_permissions')->where('code', $code)->value('id'),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('administration_role_id');
            $table->dropColumn('is_active');
            $table->dropColumn('must_change_password');
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
        Schema::dropIfExists('administration_role_permissions');
        Schema::dropIfExists('administration_permissions');
        Schema::dropIfExists('administration_roles');
    }
};
