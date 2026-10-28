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
        Schema::create('wishlist_activities', function (Blueprint $table) {
            $table->id();
            $table->uuid('save_cycle_id');
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 16);
            $table->timestamp('occurred_at')->useCurrent();
            $table->unique(['save_cycle_id', 'event_type']);
            $table->index(['occurred_at', 'event_type']);
            $table->index(['product_id', 'occurred_at', 'event_type']);
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER wishlist_activities_event_type_check BEFORE INSERT ON wishlist_activities WHEN NEW.event_type NOT IN ('added', 'removed') BEGIN SELECT RAISE(ABORT, 'Invalid wishlist activity type.'); END");
            DB::statement("CREATE TRIGGER wishlist_activities_event_type_update_check BEFORE UPDATE OF event_type ON wishlist_activities WHEN NEW.event_type NOT IN ('added', 'removed') BEGIN SELECT RAISE(ABORT, 'Invalid wishlist activity type.'); END");
        } else {
            DB::statement("ALTER TABLE wishlist_activities ADD CONSTRAINT wishlist_activities_event_type_check CHECK (event_type IN ('added', 'removed'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wishlist_activities');
    }
};
