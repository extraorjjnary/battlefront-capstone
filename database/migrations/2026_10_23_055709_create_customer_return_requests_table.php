<?php

use App\Models\Order;
use App\Models\OrderItem;
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
        Schema::create('customer_return_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->restrictOnDelete();
            $table->foreignIdFor(Order::class)->constrained()->restrictOnDelete();
            $table->foreignIdFor(OrderItem::class)->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('reason_code', 40);
            $table->text('reason')->nullable();
            $table->foreignId('customer_return_id')->nullable()->unique()->constrained('customer_returns')->restrictOnDelete();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
            $table->index(['order_item_id', 'customer_return_id', 'withdrawn_at'], 'return_request_item_pending');
            $table->index(['customer_return_id', 'withdrawn_at', 'created_at'], 'return_request_receipt_queue');
        });

        $prefix = DB::getDriverName() === 'sqlite' ? 'NEW.' : '';
        $valid = "{$prefix}quantity > 0 AND ({$prefix}withdrawn_at IS NULL OR {$prefix}customer_return_id IS NULL) AND {$prefix}reason_code IN ('wrong_item','defective','transit_damage','not_as_described','changed_mind','other') AND ({$prefix}reason_code <> 'other' OR ({$prefix}reason IS NOT NULL AND LENGTH(TRIM({$prefix}reason)) >= 5))";
        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::unprepared('CREATE TRIGGER return_request_valid_'.strtolower($operation)." BEFORE {$operation} ON customer_return_requests WHEN NOT ({$valid}) BEGIN SELECT RAISE(ABORT, 'Invalid return request'); END");
            }
            DB::unprepared("CREATE TRIGGER customer_return_requests_no_delete BEFORE DELETE ON customer_return_requests BEGIN SELECT RAISE(ABORT, 'Historical records cannot be deleted'); END");
        } else {
            DB::statement("ALTER TABLE customer_return_requests ADD CONSTRAINT return_request_valid CHECK ({$valid})");
            DB::unprepared("CREATE TRIGGER customer_return_requests_no_delete BEFORE DELETE ON customer_return_requests FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Historical records cannot be deleted'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('customer_return_requests')->exists()) {
            throw new RuntimeException('Cannot remove customer return request history.');
        }
        Schema::dropIfExists('customer_return_requests');
    }
};
