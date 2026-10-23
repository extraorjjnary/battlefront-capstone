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
        Schema::table('paymongo_flows', function (Blueprint $table): void {
            $table->string('bank_code', 20)->nullable()->after('channel');
        });

        Schema::create('paymongo_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('paymongo_flow_id')->constrained('paymongo_flows')->restrictOnDelete();
            $table->string('payment_intent_id')->unique();
            $table->string('payment_method_id')->nullable();
            $table->string('payment_id')->nullable()->unique();
            $table->string('bank_code', 20);
            $table->string('status', 32)->default('creating');
            $table->string('redirect_url', 2048)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['paymongo_flow_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('paymongo_attempts')->exists()) {
            throw new RuntimeException('PayMongo banking attempt history exists; this migration cannot be rolled back.');
        }

        Schema::dropIfExists('paymongo_attempts');
        Schema::table('paymongo_flows', function (Blueprint $table): void {
            $table->dropColumn('bank_code');
        });
    }
};
