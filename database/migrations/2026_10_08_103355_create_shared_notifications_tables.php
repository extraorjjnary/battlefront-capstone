<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['notifiable_type', 'notifiable_id', 'read_at', 'created_at'], 'notifications_recipient_read_history');
        });

        Schema::create('push_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->uuid('device_id');
            $table->foreignIdFor(PersonalAccessToken::class)->nullable()->constrained()->nullOnDelete();
            $table->string('platform', 16);
            $table->text('expo_push_token')->nullable();
            $table->char('token_hash', 64)->nullable()->unique();
            $table->uuid('registration_version');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'device_id']);
        });

        Schema::create('notification_push_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('notification_id');
            $table->foreign('notification_id')->references('id')->on('notifications')->cascadeOnDelete();
            $table->foreignId('push_device_id')->constrained()->cascadeOnDelete();
            $table->uuid('registration_version');
            $table->string('status', 32)->default('pending');
            $table->string('ticket_id')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamps();
            $table->unique(['notification_id', 'push_device_id', 'registration_version'], 'notification_device_registration_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_push_deliveries');
        Schema::dropIfExists('push_devices');
        Schema::dropIfExists('notifications');
    }
};
