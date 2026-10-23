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
            $table->timestamp('pickup_handed_over_at')->nullable();
        });
        Schema::table('customer_returns', function (Blueprint $table): void {
            $table->text('public_feedback')->nullable();
        });
        Schema::table('customer_return_requests', function (Blueprint $table): void {
            $table->string('eligibility_path', 32)->nullable();
            $table->timestamp('eligibility_receipt_at')->nullable();
            $table->timestamp('eligibility_deadline_at')->nullable();
        });
        Schema::create('customer_return_resolutions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_return_id')->unique()->constrained('customer_returns')->restrictOnDelete();
            $table->string('type', 24);
            $table->text('summary')->nullable();
            $table->string('reference')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->text('completion_notes')->nullable();
            $table->timestamps();
        });
        $prefix = DB::getDriverName() === 'sqlite' ? 'NEW.' : '';
        $valid = "{$prefix}type IN ('refund','replacement','repair','other') AND ({$prefix}type = 'refund' OR ({$prefix}summary IS NOT NULL AND LENGTH(TRIM({$prefix}summary)) >= 5)) AND (({$prefix}completed_at IS NULL AND {$prefix}completed_by IS NULL AND {$prefix}completion_notes IS NULL) OR ({$prefix}type <> 'refund' AND {$prefix}completed_at IS NOT NULL AND {$prefix}completed_by IS NOT NULL AND {$prefix}completion_notes IS NOT NULL AND LENGTH(TRIM({$prefix}completion_notes)) >= 5))";
        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::unprepared('CREATE TRIGGER customer_resolution_valid_'.strtolower($operation)." BEFORE {$operation} ON customer_return_resolutions WHEN NOT ({$valid}) BEGIN SELECT RAISE(ABORT, 'Invalid customer resolution'); END");
            }
            DB::unprepared("CREATE TRIGGER customer_return_resolutions_no_delete BEFORE DELETE ON customer_return_resolutions BEGIN SELECT RAISE(ABORT, 'Historical records cannot be deleted'); END");
        } else {
            DB::statement("ALTER TABLE customer_return_resolutions ADD CONSTRAINT customer_resolution_valid CHECK ({$valid})");
            DB::unprepared("CREATE TRIGGER customer_return_resolutions_no_delete BEFORE DELETE ON customer_return_resolutions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Historical records cannot be deleted'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('This forward-only migration preserves customer resolution and receipt history.');
    }
};
