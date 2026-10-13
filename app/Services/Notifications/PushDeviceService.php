<?php

namespace App\Services\Notifications;

use App\Enums\UserRole;
use App\Models\NotificationPushDelivery;
use App\Models\PushDevice;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class PushDeviceService
{
    /** @param array{expo_push_token: string, platform: string, notification_channel: string|null} $data */
    public function register(User $user, PersonalAccessToken $session, string $deviceId, array $data): PushDevice
    {
        try {
            return DB::transaction(function () use ($user, $session, $deviceId, $data): PushDevice {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $hash = hash('sha256', $data['expo_push_token']);
                $collision = PushDevice::query()->with(['accessToken', 'user'])->where('token_hash', $hash)->lockForUpdate()->first();
                if ($collision !== null && ($collision->user_id !== $user->id || $collision->device_id !== $deviceId)) {
                    if ($this->eligible($collision)) {
                        throw ValidationException::withMessages(['expo_push_token' => 'This push token already has an active device registration.']);
                    }
                    $this->deactivate($collision);
                }

                $device = PushDevice::query()->whereBelongsTo($user)->where('device_id', $deviceId)->lockForUpdate()->first()
                    ?? new PushDevice(['user_id' => $user->id, 'device_id' => $deviceId]);
                $sameRegistration = $device->is_active && $device->token_hash === $hash && $device->personal_access_token_id === $session->id;
                $device->fill([
                    'personal_access_token_id' => $session->id, 'platform' => $data['platform'],
                    'expo_push_token' => $data['expo_push_token'], 'token_hash' => $hash,
                    'notification_channel' => $data['platform'] === 'android' ? ($data['notification_channel'] ?? null) : null,
                    'is_active' => true,
                    'registration_version' => $sameRegistration ? $device->registration_version : (string) Str::uuid(),
                ])->save();

                return $device;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['expo_push_token' => 'This push token already has an active device registration.']);
        }
    }

    public function revoke(User $user, string $deviceId): void
    {
        $device = PushDevice::query()->whereBelongsTo($user)->where('device_id', $deviceId)->firstOrFail();
        $this->deactivate($device);
    }

    /** @return array<string, mixed>|null */
    public function status(User $user, PersonalAccessToken $session, string $deviceId): ?array
    {
        $device = PushDevice::query()->whereBelongsTo($user)->where('personal_access_token_id', $session->id)
            ->where('device_id', $deviceId)->first();

        return $device === null ? null : $this->present($device);
    }

    public function eligible(PushDevice $device): bool
    {
        $session = $device->accessToken;
        $expiration = config('sanctum.expiration');

        return $device->is_active && $device->expo_push_token !== null
            && $device->user->role === UserRole::Customer
            && $session !== null && $session->tokenable_type === $device->user->getMorphClass()
            && (int) $session->tokenable_id === $device->user_id
            && ($session->expires_at === null || $session->expires_at->isFuture())
            && ($expiration === null || $session->created_at->gt(now()->subMinutes((int) $expiration)));
    }

    public function deactivate(PushDevice $device, ?string $registrationVersion = null): void
    {
        PushDevice::query()->whereKey($device->id)
            ->when($registrationVersion !== null, fn ($query) => $query->where('registration_version', $registrationVersion))
            ->update(['is_active' => false, 'expo_push_token' => null, 'token_hash' => null, 'updated_at' => now()]);
    }

    /** @return array{id: int, device_id: string, platform: string, is_active: bool, notification_channel: string|null, updated_at: string, last_delivery: array{status: string, error_code: string|null, current_registration: bool, updated_at: string}|null} */
    public function present(PushDevice $device): array
    {
        $delivery = NotificationPushDelivery::query()->where('push_device_id', $device->id)->latest('id')->first();

        return [
            'id' => $device->id, 'device_id' => $device->device_id, 'platform' => $device->platform,
            'is_active' => $device->is_active, 'notification_channel' => $device->notification_channel,
            'updated_at' => $device->updated_at->toIso8601String(),
            'last_delivery' => $delivery === null ? null : [
                'status' => $delivery->status, 'error_code' => $delivery->error_code,
                'current_registration' => $delivery->registration_version === $device->registration_version,
                'updated_at' => $delivery->updated_at->toIso8601String(),
            ],
        ];
    }
}
