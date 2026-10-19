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
        Schema::table('supplier_returns', function (Blueprint $table) {
            $table->string('dispatch_reference')->nullable();
            $table->string('acknowledgement_reference')->nullable();
            $table->foreignId('previous_supplier_return_id')->nullable()->constrained('supplier_returns')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplier_returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('previous_supplier_return_id');
            $table->dropColumn(['dispatch_reference', 'acknowledgement_reference']);
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER supplier_returns_no_delete BEFORE DELETE ON supplier_returns BEGIN SELECT RAISE(ABORT, 'Historical records cannot be deleted'); END");
        }
    }
};
