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
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('payment_origin', 16)->default('manual')->index();
            $table->string('checkout_submission_key', 36)->nullable()->unique();
            $table->char('checkout_request_fingerprint', 64)->nullable();
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS orders_values_insert_check');
            DB::statement('DROP TRIGGER IF EXISTS orders_values_update_check');
            DB::statement(<<<'SQL'
                CREATE TRIGGER orders_values_insert_check
                BEFORE INSERT ON orders
                WHEN NEW.total_amount < 0
                    OR NEW.fulfillment_method NOT IN ('pickup', 'delivery')
                    OR NEW.status NOT IN ('pending', 'processing', 'completed', 'cancelled')
                    OR NEW.payment_status NOT IN ('pending', 'verified', 'rejected')
                    OR NEW.payment_method NOT IN ('cash', 'card_at_store', 'gcash', 'maya', 'cod', 'online')
                    OR NEW.payment_origin NOT IN ('manual', 'paymongo')
                    OR (NEW.fulfillment_method = 'pickup' AND NEW.delivery_address IS NOT NULL)
                BEGIN
                    SELECT RAISE(ABORT, 'Invalid order values.');
                END
            SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER orders_values_update_check
                BEFORE UPDATE OF total_amount, fulfillment_method, delivery_address, status, payment_status, payment_method, payment_origin ON orders
                WHEN NEW.total_amount < 0
                    OR NEW.fulfillment_method NOT IN ('pickup', 'delivery')
                    OR NEW.status NOT IN ('pending', 'processing', 'completed', 'cancelled')
                    OR NEW.payment_status NOT IN ('pending', 'verified', 'rejected')
                    OR NEW.payment_method NOT IN ('cash', 'card_at_store', 'gcash', 'maya', 'cod', 'online')
                    OR NEW.payment_origin NOT IN ('manual', 'paymongo')
                    OR (NEW.fulfillment_method = 'pickup' AND NEW.delivery_address IS NOT NULL)
                BEGIN
                    SELECT RAISE(ABORT, 'Invalid order values.');
                END
            SQL);
        } else {
            DB::statement('ALTER TABLE orders DROP CHECK orders_payment_method_valid');
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_method_valid CHECK (CAST(payment_method AS BINARY) IN ('cash', 'card_at_store', 'gcash', 'maya', 'cod', 'online'))");
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_origin_valid CHECK (CAST(payment_origin AS BINARY) IN ('manual', 'paymongo'))");
        }

        Schema::create('paymongo_flows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->string('mode', 8);
            $table->string('channel', 24);
            $table->string('status', 24)->default('creating');
            $table->string('checkout_session_id')->nullable()->unique();
            $table->string('checkout_url', 2048)->nullable();
            $table->string('payment_intent_id')->nullable()->unique();
            $table->string('payment_id')->nullable()->unique();
            $table->string('payment_method', 24)->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('PHP');
            $table->timestamp('merchant_cutoff_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'merchant_cutoff_at']);
        });

        Schema::create('paymongo_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('paymongo_flow_id')->constrained('paymongo_flows')->restrictOnDelete();
            $table->string('operation_id', 36)->unique();
            $table->string('kind', 32);
            $table->string('idempotency_key', 255)->unique();
            $table->char('request_fingerprint', 64);
            $table->string('status', 24)->default('pending');
            $table->string('provider_reference')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_check_at']);
        });

        Schema::create('paymongo_webhook_inbox', function (Blueprint $table): void {
            $table->id();
            $table->string('mode', 8);
            $table->string('event_id', 128);
            $table->string('event_type', 128);
            $table->string('resource_id', 128)->nullable();
            $table->json('verified_data');
            $table->string('status', 24)->default('received');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->timestamps();
            $table->unique(['mode', 'event_id']);
            $table->index(['status', 'next_check_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('paymongo_flows')->exists()
            || DB::table('paymongo_operations')->exists()
            || DB::table('paymongo_webhook_inbox')->exists()
            || DB::table('orders')->where('payment_origin', 'paymongo')->exists()) {
            throw new RuntimeException('PayMongo financial history exists; this migration cannot be rolled back.');
        }

        Schema::dropIfExists('paymongo_webhook_inbox');
        Schema::dropIfExists('paymongo_operations');
        Schema::dropIfExists('paymongo_flows');
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS orders_values_insert_check');
            DB::statement('DROP TRIGGER IF EXISTS orders_values_update_check');
            DB::statement(<<<'SQL'
                CREATE TRIGGER orders_values_insert_check
                BEFORE INSERT ON orders
                WHEN NEW.total_amount < 0
                    OR NEW.fulfillment_method NOT IN ('pickup', 'delivery')
                    OR NEW.status NOT IN ('pending', 'processing', 'completed', 'cancelled')
                    OR NEW.payment_status NOT IN ('pending', 'verified', 'rejected')
                    OR NEW.payment_method NOT IN ('cash', 'card_at_store', 'gcash', 'maya', 'cod')
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
                    OR NEW.payment_method NOT IN ('cash', 'card_at_store', 'gcash', 'maya', 'cod')
                    OR (NEW.fulfillment_method = 'pickup' AND NEW.delivery_address IS NOT NULL)
                BEGIN
                    SELECT RAISE(ABORT, 'Invalid order values.');
                END
            SQL);
        } else {
            DB::statement('ALTER TABLE orders DROP CHECK orders_payment_origin_valid');
            DB::statement('ALTER TABLE orders DROP CHECK orders_payment_method_valid');
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_method_valid CHECK (payment_method IN ('cash', 'card_at_store', 'gcash', 'maya', 'cod'))");
        }
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['payment_origin']);
            $table->dropUnique(['checkout_submission_key']);
            $table->dropColumn(['checkout_submission_key', 'checkout_request_fingerprint']);
            $table->dropColumn('payment_origin');
        });
    }
};
