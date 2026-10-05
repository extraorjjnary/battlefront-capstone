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
        $this->replaceMethodConstraint(true);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('forecasts')->where('method', 'additive_holt_winters')->exists()) {
            throw new RuntimeException('Cannot roll back forecast methods while Holt-Winters rows exist. Preserve these rows and use a forward migration.');
        }

        $this->replaceMethodConstraint(false);
    }

    private function replaceMethodConstraint(bool $includeHoltWinters): void
    {
        $methods = "'moving_average', 'linear_trend'";
        if ($includeHoltWinters) {
            $methods .= ", 'additive_holt_winters'";
        }

        if (DB::getDriverName() === 'sqlite') {
            foreach (['insert', 'update'] as $event) {
                DB::statement("DROP TRIGGER forecasts_valid_{$event}_check");
                $operation = $event === 'insert' ? 'INSERT' : 'UPDATE OF method, predicted_demand, forecast_quarter';
                DB::statement(<<<SQL
                    CREATE TRIGGER forecasts_valid_{$event}_check
                    BEFORE {$operation} ON forecasts
                    WHEN NEW.method NOT IN ({$methods})
                        OR NEW.predicted_demand < 0
                        OR NEW.forecast_quarter NOT GLOB '[0-9][0-9][0-9][0-9]-Q[1-4]'
                        OR substr(NEW.forecast_quarter, 1, 4) = '0000'
                    BEGIN
                        SELECT RAISE(ABORT, 'Invalid forecast.');
                    END
                SQL);
            }

            return;
        }

        DB::statement(<<<SQL
            ALTER TABLE forecasts
                DROP CHECK forecasts_method_valid,
                ADD CONSTRAINT forecasts_method_valid CHECK (CAST(method AS BINARY) IN ({$methods}))
        SQL);
    }
};
