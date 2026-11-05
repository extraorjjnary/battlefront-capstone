<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\DevelopmentCustomerSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class DemonstrationProvisioning
{
    public const PROFILE = 'defense-demo-v1';

    public function enabled(): bool
    {
        return config('catalog-demo.enabled') === true && filled(config('catalog-demo.database'))
            && DB::connection()->getDatabaseName() === config('catalog-demo.database')
            && DB::connection()->getConfig('host') === config('demonstration.host')
            && DB::connection()->getName() === config('demonstration.connection');
    }

    public function assertTarget(): void
    {
        if (! $this->enabled() || config('demonstration.provisioning_enabled') !== true) {
            throw new RuntimeException('Demonstration disclosure, temporary permission and exact connection/database match are required.');
        }
    }

    /** @return array<string, array{name: string, role: string|null}> */
    public function accounts(): array
    {
        $accounts = [
            'test@example.com' => ['name' => 'Test User', 'role' => null],
            'admin@example.com' => ['name' => 'Development Owner', 'role' => 'owner'],
            'manager@example.com' => ['name' => 'Development Manager', 'role' => 'manager'],
            'staff@example.com' => ['name' => 'Development Staff', 'role' => 'staff'],
        ];
        foreach (DevelopmentCustomerSeeder::accounts() as $email => $name) {
            $accounts[$email] = ['name' => $name, 'role' => null];
        }

        return $accounts;
    }

    /** @param array<string, mixed> $changes */
    public static function audit(string $type, int $id, array $changes = [], string $action = 'demo.record.created'): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => null, 'action' => $action, 'subject_type' => $type, 'subject_id' => $id,
            'reason' => 'Explicit Cloud capstone demonstration provisioning; fictional accounts and sales.',
            'changes' => json_encode(['profile' => self::PROFILE, ...$changes], JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }

    public static function owns(string $type, int $id): bool
    {
        return DB::table('audit_logs')->where('action', 'demo.record.created')->where('subject_type', $type)
            ->where('subject_id', $id)->get()->contains(fn ($row): bool => (json_decode($row->changes, true)['profile'] ?? null) === self::PROFILE);
    }

    /** @return array<string, int> */
    public function provision(bool $apply): array
    {
        $this->assertTarget();

        return DB::transaction(function () use ($apply): array {
            $roles = DB::table('administration_roles')->orderBy('id')->lockForUpdate()->get()->keyBy('code');
            foreach (['owner', 'manager', 'staff'] as $code) {
                if (! $roles->has($code)) {
                    throw new RuntimeException('Required administration roles are missing.');
                }
            }
            $products = DB::table('products')->where('is_catalog_imported', true)->orderBy('id')->get();
            if ($products->count() !== 654 || DB::table('inventories')->whereIn('product_id', $products->pluck('id'))->count() !== 654
                || DB::table('audit_logs')->where('action', 'catalog.demo_inventory.provisioned')->where('subject_type', 'product')
                    ->whereIn('subject_id', $products->pluck('id'))->distinct()->count('subject_id') !== 654) {
                throw new RuntimeException('Expected the preserved, audited 654-product demonstration inventory.');
            }
            $missing = [];
            $previous = DB::table('audit_logs')->where('action', 'demo.history.prepared')->orderByDesc('id')->first();
            if ($previous !== null && (json_decode($previous->changes, true)['fingerprint'] ?? null) !== $this->historyFingerprint()) {
                throw new RuntimeException('Previously prepared demonstration history is damaged; no automatic repairs are allowed.');
            }
            foreach ($this->accounts() as $email => $account) {
                $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first();
                if ($user === null) {
                    if (DB::table('audit_logs')->where('action', 'demo.record.created')->where('subject_type', 'user')
                        ->where('changes->profile', self::PROFILE)->where('changes->email', $email)->exists()) {
                        throw new RuntimeException('A previously provisioned account is missing or renamed; manual review is required.');
                    }
                    $missing[$email] = $account;

                    continue;
                }
                if (! self::owns('user', $user->id) || $user->email !== $email || $user->name !== $account['name']
                    || ! $user->hasVerifiedEmail() || $user->role !== ($account['role'] === null ? UserRole::Customer : UserRole::Administrator)
                    || $user->administration_role_id !== ($account['role'] === null ? null : (int) $roles[$account['role']]->id)) {
                    throw new RuntimeException('Conflicting demonstration account identity. No accounts were changed.');
                }
            }
            app(DemonstrationReferenceData::class)->prepare(false);
            app(HostedDemoHistory::class)->prepare(false);
            if (! $apply) {
                return ['create_accounts' => count($missing), 'preserve_accounts' => 34 - count($missing)];
            }
            $password = config('demonstration.password');
            if ($missing !== []) {
                if (! is_string($password) || in_array(strtolower($password), ['password', 'customer123'], true)) {
                    throw new RuntimeException('A private strong demonstration password is required.');
                }
                $validator = Validator::make(['password' => $password], ['password' => [Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised()]]);
                if ($validator->fails()) {
                    throw new RuntimeException('The private demonstration password failed production strength validation.');
                }
            }
            foreach ($missing as $email => $account) {
                $user = new User;
                $user->forceFill(['name' => $account['name'], 'email' => $email, 'password' => $password,
                    'role' => $account['role'] === null ? UserRole::Customer : UserRole::Administrator,
                    'administration_role_id' => $account['role'] === null ? null : $roles[$account['role']]->id,
                    'is_active' => true, 'email_verified_at' => now()])->save();
                self::audit('user', $user->id, ['email' => $email, 'role' => $account['role'] ?? 'customer',
                    'purpose' => str_starts_with($email, 'demo.customer') ? 'fictional historical-sales customer' : 'interactive defense account',
                    'verification_method' => 'administratively_preapproved_demonstration_not_otp']);
            }
            app(DemonstrationReferenceData::class)->prepare(true);
            $entries = app(HostedDemoHistory::class)->prepare(true);
            $metadata = ['entries' => $entries, 'fingerprint' => $this->historyFingerprint()];
            $last = DB::table('audit_logs')->where('action', 'demo.history.prepared')->orderByDesc('id')->first();
            if ($last === null || json_decode($last->changes, true) !== ['profile' => self::PROFILE, ...$metadata]) {
                self::audit('demonstration', 0, $metadata, 'demo.history.prepared');
            }

            return ['create_accounts' => count($missing), 'preserve_accounts' => 34 - count($missing)];
        });
    }

    public function historyFingerprint(): string
    {
        $orders = DB::table('orders')->where('development_fixture_key', 'like', HostedDemoHistory::FIXTURE_PREFIX.'%')->orderBy('id')->get();
        $data = [$orders->all()];
        foreach (['order_items', 'sales', 'shipments'] as $table) {
            $data[] = DB::table($table)->whereIn('order_id', $orders->pluck('id'))->orderBy('id')->get()->all();
        }

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function coverage(): array
    {
        if (! $this->enabled() || config('demonstration.forecasting_enabled') !== true) {
            return [];
        }
        $row = DB::table('audit_logs')->where('action', 'demo.history.prepared')->orderByDesc('id')->first();
        $metadata = $row === null ? [] : json_decode($row->changes, true);

        return ($metadata['profile'] ?? null) === self::PROFILE && ($metadata['fingerprint'] ?? null) === $this->historyFingerprint()
            ? ($metadata['entries'] ?? []) : [];
    }

    public function disableAccounts(bool $apply): int
    {
        $this->assertTarget();

        return DB::transaction(function () use ($apply): int {
            $owner = DB::table('administration_roles')->where('code', 'owner')->lockForUpdate()->first();
            $users = User::query()->whereIn('id', DB::table('audit_logs')->where('action', 'demo.record.created')
                ->where('subject_type', 'user')->where('changes->profile', self::PROFILE)
                ->whereIn('changes->email', array_keys($this->accounts()))->select('subject_id'))->lockForUpdate()->get()
                ->filter(fn (User $user): bool => self::owns('user', $user->id));
            if ($users->contains(fn (User $user): bool => $user->is_active && $user->administration_role_id === $owner?->id)
                && ! User::query()->where('role', 'administrator')->where('administration_role_id', $owner?->id)
                    ->where('is_active', true)->whereNotNull('email_verified_at')->whereNotIn('id', $users->modelKeys())->exists()) {
                throw new RuntimeException('A verified active non-demo Owner is required before retiring the demonstration Owner.');
            }
            foreach ($users as $user) {
                if (! $apply || ! $user->is_active) {
                    continue;
                }
                $user->forceFill(['is_active' => false, 'remember_token' => null])->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
                $user->tokens()->delete();
                self::audit('user', $user->id, [], 'demo.account.disabled');
            }

            return $users->count();
        });
    }
}
