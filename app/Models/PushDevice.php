<?php

namespace App\Models;

use Database\Factories\PushDeviceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @property int $id
 * @property int $user_id
 * @property string $device_id
 * @property int|null $personal_access_token_id
 * @property string $platform
 * @property string|null $expo_push_token
 * @property string|null $token_hash
 * @property string $registration_version
 * @property bool $is_active
 * @property Carbon $updated_at
 * @property-read User $user
 * @property-read PersonalAccessToken|null $accessToken
 */
#[Fillable(['user_id', 'device_id', 'personal_access_token_id', 'platform', 'expo_push_token', 'token_hash', 'registration_version', 'is_active'])]
#[Hidden(['expo_push_token', 'token_hash', 'personal_access_token_id', 'registration_version'])]
class PushDevice extends Model
{
    /** @use HasFactory<PushDeviceFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expo_push_token' => 'encrypted', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<PersonalAccessToken, $this> */
    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }
}
