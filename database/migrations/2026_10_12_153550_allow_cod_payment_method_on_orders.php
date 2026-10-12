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
        $this->replacePaymentConstraints(true);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('orders')->where('payment_method', 'cod')->exists()) {
            throw new RuntimeException('Cannot roll back payment methods while COD orders exist. Preserve these rows and use a forward migration.');
        }

        $this->replacePaymentConstraints(false);
    }

    private function replacePaymentConstraints(bool $includeCod): void
    {
        $methods = "'cash', 'card_at_store', 'gcash', 'maya'";
        if ($includeCod) {
            $methods .= ", 'cod'";
        }

        if (DB::getDriverName() === 'sqlite') {
            $codCondition = $includeCod
                ? "OR (NEW.payment_method = 'cod' AND (NEW.fulfillment_method <> 'delivery' OR NEW.payment_proof_path IS NOT NULL))"
                : '';

            foreach (['insert', 'update'] as $event) {
                DB::statement("DROP TRIGGER orders_values_{$event}_check");
                $operation = $event === 'insert'
                    ? 'INSERT'
                    : 'UPDATE OF total_amount, fulfillment_method, delivery_address, status, payment_status, payment_method, payment_proof_path';
                DB::statement(<<<SQL
                    CREATE TRIGGER orders_values_{$event}_check
                    BEFORE {$operation} ON orders
                    WHEN NEW.total_amount < 0
                        OR NEW.fulfillment_method NOT IN ('pickup', 'delivery')
                        OR NEW.status NOT IN ('pending', 'processing', 'completed', 'cancelled')
                        OR NEW.payment_status NOT IN ('pending', 'verified', 'rejected')
                        OR NEW.payment_method NOT IN ({$methods})
                        OR (NEW.fulfillment_method = 'pickup' AND NEW.delivery_address IS NOT NULL)
                        {$codCondition}
                    BEGIN
                        SELECT RAISE(ABORT, 'Invalid order values.');
                    END
                SQL);
            }

            return;
        }

        $codConstraint = $includeCod
            ? ", ADD CONSTRAINT orders_cod_delivery_valid CHECK (payment_method <> 'cod' OR (fulfillment_method = 'delivery' AND payment_proof_path IS NULL))"
            : ', DROP CHECK orders_cod_delivery_valid';

        DB::statement(<<<SQL
            ALTER TABLE orders
                DROP CHECK orders_payment_method_valid,
                ADD CONSTRAINT orders_payment_method_valid CHECK (CAST(payment_method AS BINARY) IN ({$methods}))
                {$codConstraint}
        SQL);
    }
};
