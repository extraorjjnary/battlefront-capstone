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
        $this->replaceConstraints(true);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->replaceConstraints(false);
    }

    private function replaceConstraints(bool $strict): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $yearCheck = $strict ? "OR substr(NEW.forecast_quarter, 1, 4) = '0000'" : '';
            foreach (['insert', 'update'] as $event) {
                DB::statement("DROP TRIGGER forecasts_valid_{$event}_check");
                $operation = $event === 'insert' ? 'INSERT' : 'UPDATE OF method, predicted_demand, forecast_quarter';
                DB::statement(<<<SQL
                    CREATE TRIGGER forecasts_valid_{$event}_check
                    BEFORE {$operation} ON forecasts
                    WHEN NEW.method NOT IN ('moving_average', 'linear_trend')
                        OR NEW.predicted_demand < 0
                        OR NEW.forecast_quarter NOT GLOB '[0-9][0-9][0-9][0-9]-Q[1-4]'
                        {$yearCheck}
                    BEGIN
                        SELECT RAISE(ABORT, 'Invalid forecast.');
                    END
                SQL);
            }

            return;
        }

        $methodCheck = $strict
            ? "CAST(method AS BINARY) IN ('moving_average', 'linear_trend')"
            : "method IN ('moving_average', 'linear_trend')";
        $quarterCheck = $strict
            ? "REGEXP_LIKE(forecast_quarter, '^[0-9]{4}-Q[1-4]$', 'c') AND LEFT(forecast_quarter, 4) <> '0000' AND CHAR_LENGTH(forecast_quarter) = 7"
            : "forecast_quarter REGEXP '^[0-9]{4}-Q[1-4]$'";

        DB::statement(<<<SQL
            ALTER TABLE forecasts
                DROP CHECK forecasts_method_valid,
                DROP CHECK forecasts_quarter_format,
                ADD CONSTRAINT forecasts_method_valid CHECK ({$methodCheck}),
                ADD CONSTRAINT forecasts_quarter_format CHECK ({$quarterCheck})
        SQL);
    }
};
