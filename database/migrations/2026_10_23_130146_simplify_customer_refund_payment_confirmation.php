<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customer_refund_payments', function (Blueprint $table): void {
            $table->string('external_reference')->nullable()->change();
            $table->uuid('submission_key')->nullable()->unique();
        });
        Schema::table('customer_refund_payment_reviews', function (Blueprint $table): void {
            $table->string('confirmation_method', 24)->nullable();
        });
        Schema::table('administration_evidence', function (Blueprint $table): void {
            $table->boolean('customer_visible')->default(false);
        });
        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared('DROP TRIGGER IF EXISTS customer_refund_payments_no_'.strtolower($operation));
                DB::unprepared('CREATE TRIGGER customer_refund_payments_no_'.strtolower($operation)." BEFORE {$operation} ON customer_refund_payments BEGIN SELECT RAISE(ABORT, 'Posted history cannot be changed'); END");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('This forward-only migration preserves refund confirmations, proof visibility and historical references.');
    }
};
