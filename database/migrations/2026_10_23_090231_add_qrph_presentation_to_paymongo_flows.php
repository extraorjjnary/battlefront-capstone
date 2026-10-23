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
            $table->longText('qr_code_image_url')->nullable();
            $table->timestamp('qr_expires_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('paymongo_flows')->where('channel', 'direct_qrph')->exists()) {
            throw new RuntimeException('PayMongo QR Ph payment history exists; this migration cannot be rolled back.');
        }

        Schema::table('paymongo_flows', function (Blueprint $table): void {
            $table->dropColumn(['qr_code_image_url', 'qr_expires_at']);
        });
    }
};
