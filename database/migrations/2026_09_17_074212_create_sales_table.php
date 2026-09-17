<?php

use App\Models\Order;
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
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Order::class)->unique()->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('sale_date');
        });

        $this->addAmountConstraint();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales');
    }

    /**
     * Enforce non-negative sale amounts on MySQL and the SQLite test database.
     */
    private function addAmountConstraint(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER sales_amount_insert_check
                BEFORE INSERT ON sales
                WHEN NEW.amount < 0
                BEGIN
                    SELECT RAISE(ABORT, 'Sale amount cannot be negative.');
                END
            SQL);

            DB::statement(<<<'SQL'
                CREATE TRIGGER sales_amount_update_check
                BEFORE UPDATE OF amount ON sales
                WHEN NEW.amount < 0
                BEGIN
                    SELECT RAISE(ABORT, 'Sale amount cannot be negative.');
                END
            SQL);

            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE sales
                ADD CONSTRAINT sales_amount_non_negative CHECK (amount >= 0)
        SQL);
    }
};
