<?php

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
        Schema::table('inventories', function (Blueprint $table) {
            $table->unsignedInteger('quarantined_quantity')->default(0);
        });
        Schema::create('inventory_adjustment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('observed_quantity');
            $table->unsignedInteger('target_quantity');
            $table->unsignedInteger('initial_reorder_level')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('reason');
            $table->text('decision_reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('kind', 40);
            $table->string('reference_type', 80)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->unsignedInteger('sellable_before');
            $table->integer('sellable_delta');
            $table->unsignedInteger('sellable_after');
            $table->unsignedInteger('quarantined_before');
            $table->integer('quarantined_delta');
            $table->unsignedInteger('quarantined_after');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['reference_type', 'reference_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_adjustment_requests');
        Schema::table('inventories', function (Blueprint $table) {
            $table->dropColumn('quarantined_quantity');
        });
    }
};
