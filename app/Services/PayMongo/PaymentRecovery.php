<?php

namespace App\Services\PayMongo;

use App\Models\Order;
use App\Models\PayMongoFlow;
use Closure;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class PaymentRecovery
{
    /** @var array<int, bool> */
    private static array $held = [];

    public static function locked(int $orderId, Closure $callback): mixed
    {
        if (isset(self::$held[$orderId])) {
            return $callback();
        }
        $lock = Cache::lock('paymongo-order:'.$orderId, 120);
        if (! $lock->get()) {
            throw new RuntimeException('Payment recovery is already in progress.');
        }
        self::$held[$orderId] = true;
        try {
            return $callback();
        } finally {
            unset(self::$held[$orderId]);
            $lock->release();
        }
    }

    public function observed(PayMongoFlow $flow, string $outcome): void
    {
        DB::transaction(function () use ($flow, $outcome): void {
            Order::query()->lockForUpdate()->findOrFail($flow->order_id);
            $current = PayMongoFlow::query()->lockForUpdate()->findOrFail($flow->id);
            if ($current->requires_attention && $current->recovery_reason === 'financial_anomaly') {
                $current->update(['last_checked_at' => now(), 'next_check_at' => now()->addMinutes(15)]);

                return;
            }
            if ($current->status === 'paid') {
                $outcome = 'paid';
            }
            $current->update([
                'recovery_outcome' => $outcome, 'recovery_reason' => null,
                'last_checked_at' => now(), 'next_check_at' => now()->addMinute(),
                'exception_since' => null, 'recovery_failures' => 0, 'requires_attention' => false,
            ]);
        });
    }

    public function uncertain(PayMongoFlow $flow, ?Throwable $exception = null, string $reason = 'provider_uncertain'): void
    {
        DB::transaction(function () use ($flow, $exception, $reason): void {
            Order::query()->lockForUpdate()->findOrFail($flow->order_id);
            $current = PayMongoFlow::query()->lockForUpdate()->findOrFail($flow->id);
            if ($current->status === 'paid' && $reason !== 'financial_anomaly') {
                return;
            }
            $failures = $current->recovery_failures + 1;
            $minutes = [1, 2, 5, 15][min($failures - 1, 3)];
            if ($exception instanceof RequestException) {
                $status = $exception->response->status();
                if (in_array($status, [401, 403], true)) {
                    $reason = 'provider_configuration';
                    $minutes = 15;
                }
                if ($status === 429) {
                    $reason = 'provider_rate_limit';
                    $delay = $exception->response->header('Retry-After');
                    if (ctype_digit((string) $delay)) {
                        $minutes = max($minutes, (int) ceil((int) $delay / 60));
                    } elseif (strtotime($delay) !== false) {
                        $minutes = max($minutes, (int) ceil((strtotime($delay) - time()) / 60));
                    }
                }
            }
            $since = $current->exception_since ?? now();
            $attention = $current->requires_attention || $reason === 'financial_anomaly'
                || $reason === 'provider_configuration' || $since->lessThanOrEqualTo(now()->subMinutes(15));
            $newException = $attention && ! $current->requires_attention;
            $current->update([
                'status' => 'reconciliation_required', 'recovery_outcome' => 'checking',
                'recovery_reason' => $current->recovery_reason === 'financial_anomaly' ? 'financial_anomaly' : $reason,
                'exception_since' => $since, 'recovery_failures' => $failures,
                'next_check_at' => now()->addMinutes($minutes), 'requires_attention' => $attention,
            ]);
            if ($newException) {
                $this->audit($flow->order_id, 'payment.recovery_exception', ['flow_id' => $flow->id, 'reason' => $reason]);
            }
        });
    }

    /** @param array<string, mixed> $changes */
    public function audit(int $orderId, string $action, array $changes, ?int $actorId = null): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => $actorId, 'action' => $action, 'subject_type' => 'order',
            'subject_id' => $orderId, 'changes' => json_encode($changes, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
