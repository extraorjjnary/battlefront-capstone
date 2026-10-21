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
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE orders ADD COLUMN assigned_handler_id INTEGER REFERENCES users(id) ON DELETE RESTRICT');
            DB::statement('ALTER TABLE orders ADD COLUMN handler_required INTEGER NOT NULL DEFAULT 0');
        } else {
            Schema::table('orders', function (Blueprint $table) {
                $table->foreignId('assigned_handler_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->boolean('handler_required')->default(false);
            });
        }
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('handler_id')->nullable()->constrained('users')->restrictOnDelete();
        });
        $this->installSqliteSalesAmountTriggers();
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('contact_name')->nullable();
            $table->string('contact_number', 30)->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('damage_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('status', 20)->default('pending');
            $table->string('disposition', 30)->nullable();
            $table->text('reason');
            $table->timestamps();
        });
        Schema::create('customer_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->text('reason');
            $table->timestamps();
        });
        Schema::create('customer_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_return_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('condition', 30);
            $table->string('disposition', 30)->nullable();
            $table->unique(['customer_return_id', 'order_item_id']);
        });
        Schema::create('customer_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_return_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->decimal('approved_amount', 12, 2);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->string('payment_method', 40)->nullable();
            $table->string('external_reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
        Schema::create('customer_refund_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_refund_id')->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 40);
            $table->string('external_reference');
            $table->timestamp('paid_at');
            $table->unique(['customer_refund_id', 'external_reference'], 'refund_payment_reference_unique');
        });
        Schema::create('supplier_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('damage_report_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_return_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('status', 20)->default('pending');
            $table->string('external_reference')->nullable();
            $table->text('reason');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_returns');
        Schema::dropIfExists('customer_refund_payments');
        Schema::dropIfExists('customer_refunds');
        Schema::dropIfExists('customer_return_items');
        Schema::dropIfExists('customer_returns');
        Schema::dropIfExists('damage_reports');
        Schema::dropIfExists('suppliers');
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('handler_id');
        });
        $this->installSqliteSalesAmountTriggers();
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_handler_id');
            $table->dropColumn('handler_required');
        });
    }

    private function installSqliteSalesAmountTriggers(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE TRIGGER sales_amount_insert_check
            BEFORE INSERT ON sales
            WHEN CAST(NEW.amount AS NUMERIC) < 0
            BEGIN
                SELECT RAISE(ABORT, 'Sale amount cannot be negative.');
            END
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER sales_amount_update_check
            BEFORE UPDATE OF amount ON sales
            WHEN CAST(NEW.amount AS NUMERIC) < 0
            BEGIN
                SELECT RAISE(ABORT, 'Sale amount cannot be negative.');
            END
        SQL);
    }
};
