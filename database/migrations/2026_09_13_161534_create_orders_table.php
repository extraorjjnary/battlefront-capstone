<?php

use App\Models\User;
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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->restrictOnDelete();
            $table->string('recipient_name');
            $table->string('contact_number', 20);
            $table->string('fulfillment_method', 16);
            $table->string('delivery_address')->nullable();
            $table->decimal('total_amount', 12, 2);
            $table->string('status', 16)->default('pending');
            $table->string('payment_status', 16)->default('pending');
            $table->string('payment_method', 32);
            $table->string('payment_proof_path')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        $this->addOrderConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }

    /**
     * Enforce approved order values on MySQL and the SQLite test database.
     */
    private function addOrderConstraints(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER orders_values_insert_check
                BEFORE INSERT ON orders
                WHEN NEW.total_amount < 0
                    OR NEW.fulfillment_method NOT IN ('pickup', 'delivery')
                    OR NEW.status NOT IN ('pending', 'processing', 'completed', 'cancelled')
                    OR NEW.payment_status NOT IN ('pending', 'verified', 'rejected')
                    OR NEW.payment_method NOT IN ('cash', 'card_at_store', 'gcash', 'maya')
                    OR (NEW.fulfillment_method = 'pickup' AND NEW.delivery_address IS NOT NULL)
                BEGIN
                    SELECT RAISE(ABORT, 'Invalid order values.');
                END
            SQL);

            DB::statement(<<<'SQL'
                CREATE TRIGGER orders_values_update_check
                BEFORE UPDATE OF total_amount, fulfillment_method, delivery_address, status, payment_status, payment_method ON orders
                WHEN NEW.total_amount < 0
                    OR NEW.fulfillment_method NOT IN ('pickup', 'delivery')
                    OR NEW.status NOT IN ('pending', 'processing', 'completed', 'cancelled')
                    OR NEW.payment_status NOT IN ('pending', 'verified', 'rejected')
                    OR NEW.payment_method NOT IN ('cash', 'card_at_store', 'gcash', 'maya')
                    OR (NEW.fulfillment_method = 'pickup' AND NEW.delivery_address IS NOT NULL)
                BEGIN
                    SELECT RAISE(ABORT, 'Invalid order values.');
                END
            SQL);

            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE orders
                ADD CONSTRAINT orders_total_amount_non_negative CHECK (total_amount >= 0),
                ADD CONSTRAINT orders_fulfillment_method_valid CHECK (fulfillment_method IN ('pickup', 'delivery')),
                ADD CONSTRAINT orders_status_valid CHECK (status IN ('pending', 'processing', 'completed', 'cancelled')),
                ADD CONSTRAINT orders_payment_status_valid CHECK (payment_status IN ('pending', 'verified', 'rejected')),
                ADD CONSTRAINT orders_payment_method_valid CHECK (payment_method IN ('cash', 'card_at_store', 'gcash', 'maya')),
                ADD CONSTRAINT orders_pickup_address_null CHECK (fulfillment_method <> 'pickup' OR delivery_address IS NULL)
        SQL);
    }
};
