<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_devices', function (Blueprint $table): void {
            $table->string('notification_channel', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('push_devices', function (Blueprint $table): void {
            $table->dropColumn('notification_channel');
        });
    }
};
