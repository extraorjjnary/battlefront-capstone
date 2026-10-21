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
        if (! Schema::hasColumn('inventory_adjustment_requests', 'replaces_request_id')) {
            Schema::table('inventory_adjustment_requests', function (Blueprint $table) {
                $table->foreignId('replaces_request_id')->nullable()->unique()->constrained('inventory_adjustment_requests')->restrictOnDelete();
            });
        }
        foreach (['customer_returns', 'damage_reports', 'supplier_returns'] as $tableName) {
            if (! Schema::hasColumn($tableName, 'decision_reason')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->text('decision_reason')->nullable();
                });
            }
        }

        if (! Schema::hasTable('supplier_return_resolutions')) {
            Schema::create('supplier_return_resolutions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('supplier_return_id')->unique()->constrained()->restrictOnDelete();
                $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
                $table->string('outcome', 32);
                $table->string('external_reference');
                $table->text('notes');
                $table->timestamp('created_at');
            });
        }
        if (Schema::hasTable('customer_refund_payment_reviews')) {
            if (DB::table('customer_refund_payment_reviews')->exists()) {
                throw new RuntimeException('An incomplete refund review table contains records; inspect it before retrying the migration.');
            }
            Schema::drop('customer_refund_payment_reviews');
        }
        Schema::create('customer_refund_payment_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_refund_payment_id');
            $table->foreignId('reviewed_by');
            $table->string('decision', 16);
            $table->text('reason');
            $table->timestamp('created_at');
            $table->index(['customer_refund_payment_id', 'id'], 'refund_review_payment_id_index');
            $table->foreign('customer_refund_payment_id', 'refund_review_payment_fk')->references('id')->on('customer_refund_payments')->restrictOnDelete();
            $table->foreign('reviewed_by', 'refund_review_user_fk')->references('id')->on('users')->restrictOnDelete();
        });
        if (! Schema::hasTable('administration_evidence')) {
            Schema::create('administration_evidence', function (Blueprint $table) {
                $table->id();
                $table->string('subject_type', 32);
                $table->unsignedBigInteger('subject_id');
                $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
                $table->string('path')->unique();
                $table->string('original_name');
                $table->string('mime_type', 80);
                $table->timestamp('created_at');
                $table->index(['subject_type', 'subject_id']);
            });
        }

        foreach (['returns.resolve', 'returns.reconcile'] as $code) {
            DB::table('administration_permissions')->insertOrIgnore(['code' => $code, 'name' => $code]);
            $permissionId = DB::table('administration_permissions')->where('code', $code)->value('id');
            foreach (['manager', 'owner'] as $roleCode) {
                DB::table('administration_role_permissions')->insertOrIgnore([
                    'administration_role_id' => DB::table('administration_roles')->where('code', $roleCode)->value('id'),
                    'administration_permission_id' => $permissionId,
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('administration_evidence')->exists()
            || DB::table('customer_refund_payment_reviews')->exists()
            || DB::table('supplier_return_resolutions')->exists()
            || DB::table('inventory_adjustment_requests')->whereNotNull('replaces_request_id')->exists()
            || DB::table('customer_returns')->whereNotNull('decision_reason')->exists()
            || DB::table('damage_reports')->whereNotNull('decision_reason')->exists()
            || DB::table('supplier_returns')->whereNotNull('decision_reason')->exists()) {
            throw new RuntimeException('Administration workflow history exists; this migration cannot be rolled back without losing it.');
        }

        $permissionIds = DB::table('administration_permissions')->whereIn('code', ['returns.resolve', 'returns.reconcile'])->pluck('id');
        DB::table('administration_role_permissions')->whereIn('administration_permission_id', $permissionIds)->delete();
        DB::table('administration_permissions')->whereIn('id', $permissionIds)->delete();
        Schema::dropIfExists('administration_evidence');
        Schema::dropIfExists('customer_refund_payment_reviews');
        Schema::dropIfExists('supplier_return_resolutions');
        foreach (['customer_returns', 'damage_reports', 'supplier_returns'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('decision_reason');
            });
            $this->restoreSqliteDeleteTrigger($tableName);
        }
        Schema::table('inventory_adjustment_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replaces_request_id');
        });
        $this->restoreSqliteDeleteTrigger('inventory_adjustment_requests');
    }

    private function restoreSqliteDeleteTrigger(string $tableName): void
    {
        if (DB::getDriverName() === 'sqlite') {
            if ($tableName === 'customer_returns') {
                DB::unprepared('DROP TRIGGER IF EXISTS customer_returns_no_delete');
                DB::unprepared("CREATE TRIGGER customer_returns_no_delete BEFORE DELETE ON customer_returns BEGIN SELECT RAISE(ABORT, 'Historical records cannot be deleted'); END");
            } elseif ($tableName === 'damage_reports') {
                DB::unprepared('DROP TRIGGER IF EXISTS damage_reports_no_delete');
                DB::unprepared("CREATE TRIGGER damage_reports_no_delete BEFORE DELETE ON damage_reports BEGIN SELECT RAISE(ABORT, 'Historical records cannot be deleted'); END");
            } elseif ($tableName === 'supplier_returns') {
                DB::unprepared('DROP TRIGGER IF EXISTS supplier_returns_no_delete');
                DB::unprepared("CREATE TRIGGER supplier_returns_no_delete BEFORE DELETE ON supplier_returns BEGIN SELECT RAISE(ABORT, 'Historical records cannot be deleted'); END");
            } elseif ($tableName === 'inventory_adjustment_requests') {
                DB::unprepared('DROP TRIGGER IF EXISTS inventory_adjustment_requests_no_delete');
                DB::unprepared("CREATE TRIGGER inventory_adjustment_requests_no_delete BEFORE DELETE ON inventory_adjustment_requests BEGIN SELECT RAISE(ABORT, 'Historical records cannot be deleted'); END");
            } else {
                throw new InvalidArgumentException('Unknown historical table.');
            }
        }
    }
};
