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
        foreach (['customer_searches', 'customer_product_views'] as $tableName) {
            if (DB::table($tableName)->whereRaw('(user_id IS NULL) = (guest_recommendation_profile_id IS NULL)')->exists()) {
                throw new RuntimeException('Recommendation activity has invalid ownership; correct it explicitly before migrating.');
            }
        }

        foreach (['customer_searches', 'customer_product_views'] as $tableName) {
            $constraint = $tableName.'_one_owner';

            if (DB::getDriverName() === 'sqlite') {
                foreach (['INSERT', 'UPDATE'] as $operation) {
                    $trigger = $constraint.'_'.strtolower($operation);
                    DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$operation} ON {$tableName}
                        WHEN (NEW.user_id IS NULL) = (NEW.guest_recommendation_profile_id IS NULL)
                        BEGIN SELECT RAISE(ABORT, 'Recommendation activity must have exactly one owner'); END");
                }
            } elseif (DB::getDriverName() === 'mysql') {
                DB::statement("ALTER TABLE {$tableName} ADD CONSTRAINT {$constraint} CHECK ((user_id IS NULL) <> (guest_recommendation_profile_id IS NULL))");
            } else {
                throw new RuntimeException('Recommendation ownership supports the approved MySQL and SQLite databases only.');
            }
        }

        Schema::table('customer_product_views', function (Blueprint $table): void {
            $table->index(['user_id', 'created_at', 'id'], 'customer_views_user_created_idx');
            $table->index(['guest_recommendation_profile_id', 'created_at', 'id'], 'customer_views_guest_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Recommendation ownership protection is forward-only; preserve retained activity and apply a corrective migration.');
    }
};
