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
        $this->dropSqliteDeleteTrigger();

        Schema::table('customer_returns', function (Blueprint $table) {
            $table->string('status', 32)->default('pending')->change();
        });

        $this->restoreSqliteDeleteTrigger();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('customer_returns')->whereRaw('LENGTH(status) > 20')->exists()) {
            throw new RuntimeException('Customer return statuses exceed 20 characters; the status column cannot be narrowed safely.');
        }

        $this->dropSqliteDeleteTrigger();

        Schema::table('customer_returns', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->change();
        });

        $this->restoreSqliteDeleteTrigger();
    }

    private function dropSqliteDeleteTrigger(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS customer_returns_no_delete');
        }
    }

    private function restoreSqliteDeleteTrigger(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER customer_returns_no_delete BEFORE DELETE ON customer_returns BEGIN SELECT RAISE(ABORT, 'Historical records cannot be deleted'); END");
        }
    }
};
