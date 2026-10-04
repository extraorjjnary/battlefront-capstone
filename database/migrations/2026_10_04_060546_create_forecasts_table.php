<?php

use App\Models\Product;
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
        Schema::create('forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Product::class)->constrained()->restrictOnDelete();
            $table->string('method', 32);
            $table->decimal('predicted_demand', 12, 2);
            $table->string('forecast_quarter', 7);
            $table->timestamp('generated_at');

            $table->unique(['product_id', 'method', 'forecast_quarter']);
        });

        $this->addForecastConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('forecasts');
    }

    private function addForecastConstraints(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER forecasts_valid_insert_check
                BEFORE INSERT ON forecasts
                WHEN NEW.method NOT IN ('moving_average', 'linear_trend')
                    OR NEW.predicted_demand < 0
                    OR NEW.forecast_quarter NOT GLOB '[0-9][0-9][0-9][0-9]-Q[1-4]'
                BEGIN
                    SELECT RAISE(ABORT, 'Invalid forecast.');
                END
            SQL);

            DB::statement(<<<'SQL'
                CREATE TRIGGER forecasts_valid_update_check
                BEFORE UPDATE OF method, predicted_demand, forecast_quarter ON forecasts
                WHEN NEW.method NOT IN ('moving_average', 'linear_trend')
                    OR NEW.predicted_demand < 0
                    OR NEW.forecast_quarter NOT GLOB '[0-9][0-9][0-9][0-9]-Q[1-4]'
                BEGIN
                    SELECT RAISE(ABORT, 'Invalid forecast.');
                END
            SQL);

            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE forecasts
                ADD CONSTRAINT forecasts_method_valid CHECK (method IN ('moving_average', 'linear_trend')),
                ADD CONSTRAINT forecasts_demand_non_negative CHECK (predicted_demand >= 0),
                ADD CONSTRAINT forecasts_quarter_format CHECK (forecast_quarter REGEXP '^[0-9]{4}-Q[1-4]$')
        SQL);
    }
};
