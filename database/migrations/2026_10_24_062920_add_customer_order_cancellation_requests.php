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
        Schema::create('order_cancellation_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->text('customer_note')->nullable();
            $table->string('status', 16)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->decimal('amount_received', 12, 2)->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
        Schema::table('customer_refunds', function (Blueprint $table): void {
            $table->unsignedBigInteger('customer_return_id')->nullable()->change();
            $table->foreignId('cancellation_request_id')->nullable()->unique()
                ->constrained('order_cancellation_requests')->restrictOnDelete();
        });

        $sourceCheck = '(customer_return_id IS NOT NULL AND cancellation_request_id IS NULL) OR (customer_return_id IS NULL AND cancellation_request_id IS NOT NULL)';
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE customer_refunds ADD CONSTRAINT customer_refunds_one_source CHECK ({$sourceCheck})");
            DB::unprepared("CREATE TRIGGER order_cancellation_requests_no_delete BEFORE DELETE ON order_cancellation_requests FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Historical records cannot be deleted'");
            DB::unprepared("CREATE TRIGGER order_cancellation_requests_final BEFORE UPDATE ON order_cancellation_requests FOR EACH ROW BEGIN IF OLD.status <> 'pending' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cancellation decisions cannot be changed'; END IF; END");
        } else {
            $sqliteSourceCheck = '(NEW.customer_return_id IS NOT NULL AND NEW.cancellation_request_id IS NULL) OR (NEW.customer_return_id IS NULL AND NEW.cancellation_request_id IS NOT NULL)';
            foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $operation) {
                DB::unprepared("CREATE TRIGGER customer_refunds_source_{$suffix} BEFORE {$operation} ON customer_refunds WHEN NOT ({$sqliteSourceCheck}) BEGIN SELECT RAISE(ABORT, 'A refund must have exactly one source'); END");
            }
            DB::unprepared("CREATE TRIGGER order_cancellation_requests_no_delete BEFORE DELETE ON order_cancellation_requests BEGIN SELECT RAISE(ABORT, 'Historical records cannot be deleted'); END");
            DB::unprepared("CREATE TRIGGER order_cancellation_requests_final BEFORE UPDATE ON order_cancellation_requests WHEN OLD.status <> 'pending' BEGIN SELECT RAISE(ABORT, 'Cancellation decisions cannot be changed'); END");
            DB::unprepared('DROP TRIGGER IF EXISTS customer_refunds_no_delete');
            DB::unprepared("CREATE TRIGGER customer_refunds_no_delete BEFORE DELETE ON customer_refunds BEGIN SELECT RAISE(ABORT, 'Historical records cannot be deleted'); END");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('This forward-only migration preserves cancellation decisions and refund history.');
    }
};
