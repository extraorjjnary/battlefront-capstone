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
        if (! Schema::hasColumn('paymongo_flows', 'active_slot')) {
            Schema::table('paymongo_flows', function (Blueprint $table): void {
                $table->string('active_slot', 16)->nullable()->after('order_id');
            });
        }

        if (! Schema::hasColumn('paymongo_flows', 'hosted_payment_method')) {
            Schema::table('paymongo_flows', function (Blueprint $table): void {
                $table->string('hosted_payment_method', 24)->nullable()->after('channel');
            });
        }

        DB::table('paymongo_flows')
            ->whereNotIn('status', ['closed', 'paid'])
            ->update(['active_slot' => 'active']);

        if (! Schema::hasIndex('paymongo_flows', ['order_id', 'active_slot'])) {
            Schema::table('paymongo_flows', function (Blueprint $table): void {
                $table->unique(['order_id', 'active_slot'], 'paymongo_flows_order_active_unique');
            });
        }

        if (Schema::hasIndex('paymongo_flows', ['order_id'], 'unique')) {
            Schema::table('paymongo_flows', function (Blueprint $table): void {
                $table->dropUnique('paymongo_flows_order_id_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('paymongo_flows')->select('order_id')->groupBy('order_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Multiple PayMongo flow history exists; this migration cannot be rolled back.');
        }

        Schema::table('paymongo_flows', function (Blueprint $table): void {
            $table->unique('order_id', 'paymongo_flows_order_id_unique');
        });

        Schema::table('paymongo_flows', function (Blueprint $table): void {
            $table->dropUnique('paymongo_flows_order_active_unique');
            $table->dropColumn(['active_slot', 'hosted_payment_method']);
        });
    }
};
