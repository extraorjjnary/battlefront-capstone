<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $addIndexes = static function (Blueprint $table): void {
            $table->unique('event_id', 'rec_interactions_event_unique');
            $table->index(['placement', 'event_type', 'created_at'], 'rec_interactions_place_type_created_idx');
            $table->index(['product_id', 'created_at'], 'rec_interactions_product_created_idx');
            $table->index(['created_at', 'event_type'], 'rec_interactions_created_type_idx');
            $table->index('expires_at', 'rec_interactions_expires_idx');
        };

        if (Schema::hasTable('recommendation_interactions')) {
            Schema::table('recommendation_interactions', $addIndexes);

            return;
        }

        Schema::create('recommendation_interactions', function (Blueprint $table) use ($addIndexes): void {
            $table->id();
            $table->uuid('event_id');
            $table->foreignIdFor(Product::class)->constrained()->cascadeOnDelete();
            $table->string('event_type', 16);
            $table->string('placement', 16);
            $table->unsignedTinyInteger('position');
            $table->string('reason_code', 64)->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $addIndexes($table);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recommendation_interactions');
    }
};
