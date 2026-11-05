<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PcBuild;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DemonstrationRehearsal
{
    public const PROFILE = 'defense-rehearsal-v1';

    /** @return array{run: string, run_record: int, user_id: int|null, expires: int} */
    public function begin(string $run, ?int $userId): array
    {
        abort_unless(app(DemonstrationProvisioning::class)->enabled() && config('demonstration.rehearsal_enabled') === true, 404);

        return Cache::lock('battlefront-demo-rehearsal', 120)->block(10, function () use ($run, $userId): array {
            $row = DB::table('audit_logs')->where('action', 'demo.rehearsal.started')->where('changes->profile', self::PROFILE)->where('changes->run', $run)->first();
            if ($row === null) {
                $this->audit('demo.rehearsal.started', 'run', 0, ['run' => $run]);
                $row = DB::table('audit_logs')->where('action', 'demo.rehearsal.started')->where('changes->run', $run)->orderByDesc('id')->first();
            }
            abort_if($this->evidence('demo.rehearsal.closed', 'run', $row->id) !== null, 409, 'Run closed. Configure a new run UUID.');

            return ['run' => $run, 'run_record' => $row->id, 'user_id' => $userId, 'expires' => now()->addHour()->timestamp];
        });
    }

    /** @param array<string, mixed> $data */
    public function audit(string $action, string $type, int $id, array $data = []): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => auth()->id(), 'action' => $action, 'subject_type' => $type, 'subject_id' => $id,
            'reason' => 'Explicit fictional rehearsal; no real funds or goods transferred.',
            'changes' => json_encode(['profile' => self::PROFILE, ...$data], JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function evidence(string $action, string $type, int $id): ?array
    {
        $row = DB::table('audit_logs')->where('action', $action)->where('subject_type', $type)->where('subject_id', $id)
            ->where('changes->profile', self::PROFILE)->orderByDesc('id')->lockForUpdate()->first();

        return $row === null ? null : json_decode($row->changes, true, flags: JSON_THROW_ON_ERROR);
    }

    public function fingerprint(Model $model): string
    {
        $data = $model->fresh()->getAttributes();
        if ($model instanceof User) {
            $data = array_intersect_key($data, array_flip(['id', 'email', 'role', 'administration_role_id']));
        }
        if ($model instanceof Order) {
            $data = array_intersect_key($data, array_flip(['id', 'user_id', 'created_at', 'development_fixture_key', 'recipient_name', 'contact_number', 'fulfillment_method', 'payment_method', 'payment_origin', 'product_subtotal', 'delivery_fee', 'total_amount', 'checkout_submission_key']));
            $data['items'] = DB::table('order_items')->where('order_id', $model->getKey())->orderBy('id')->get()->all();
        }
        if ($model instanceof PcBuild) {
            $data['items'] = DB::table('pc_build_items')->where('pc_build_id', $model->getKey())->orderBy('id')->get()->all();
        }

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function record(string $type, Model $model): void
    {
        $context = request()->attributes->get('demonstration_rehearsal');
        if (! is_array($context) || ! app(DemonstrationProvisioning::class)->enabled()) {
            return;
        }
        $this->assertOpenContext($context);
        $this->audit('demo.rehearsal.created', $type, $model->getKey(), [
            'run' => $context['run'], 'owner_id' => $type === 'user' ? $model->getKey() : ($model->user_id ?? auth()->id()),
            'fingerprint' => $this->fingerprint($model),
        ]);
        if ($type === 'user' && request()->hasSession()) {
            request()->session()->put('demonstration_rehearsal.user_id', $model->getKey());
        }
    }

    public function recordSale(Order $order): void
    {
        $created = $this->evidence('demo.rehearsal.created', 'order', $order->id);
        $context = request()->attributes->get('demonstration_rehearsal');
        if ($created === null || ! is_array($context) || $created['run'] !== $context['run']
            || auth()->id() !== $order->assigned_handler_id || ! auth()->user()?->hasAdministrationPermission('orders.process')) {
            return;
        }
        $this->assertOpenContext($context);
        $this->audit('demo.rehearsal.sale.posted', 'order', $order->id, [
            'run' => $created['run'], 'fingerprint' => $this->fingerprint($order), 'sale' => $order->sale()->firstOrFail()->getAttributes(),
        ]);
    }

    public function matches(string $type, Model $model): bool
    {
        $evidence = $this->evidence('demo.rehearsal.created', $type, $model->getKey());

        return $evidence !== null && $this->evidence('demo.rehearsal.detached', $type, $model->getKey()) === null
            && DB::table('audit_logs')->where('action', 'demo.rehearsal.started')->where('changes->profile', self::PROFILE)->where('changes->run', $evidence['run'])->exists()
            && hash_equals($evidence['fingerprint'], $this->fingerprint($model));
    }

    public function detachChangedRecord(string $type, Model $model): void
    {
        if (! app(DemonstrationProvisioning::class)->enabled() || ! $model->isDirty()
            || ($model instanceof User && array_intersect(array_keys($model->getDirty()), ['email', 'role', 'administration_role_id']) === [])
            || $this->evidence('demo.rehearsal.created', $type, $model->getKey()) === null
            || $this->evidence('demo.rehearsal.detached', $type, $model->getKey()) !== null) {
            return;
        }
        $this->audit('demo.rehearsal.detached', $type, $model->getKey(), ['reason' => 'Record changed after creation; automatic cleanup eligibility withdrawn.']);
    }

    /** @param array<string, mixed> $context */
    private function assertOpenContext(array $context): void
    {
        $run = DB::table('audit_logs')->where('id', $context['run_record'])->lockForUpdate()->first();
        abort_unless($run !== null && $run->action === 'demo.rehearsal.started'
            && (json_decode($run->changes, true)['run'] ?? null) === $context['run']
            && $this->evidence('demo.rehearsal.closed', 'run', $run->id) === null, 409, 'Rehearsal run is closed.');
    }

    /** @return list<int> */
    public function archivedOrderIds(): array
    {
        if (! app(DemonstrationProvisioning::class)->enabled()) {
            return [];
        }
        $ids = DB::table('audit_logs')->where('action', 'demo.cleanup.sale.archived')->where('subject_type', 'order')
            ->where('changes->profile', self::PROFILE)->distinct()->pluck('subject_id');

        $archived = [];
        foreach (Order::query()->whereKey($ids)->get() as $order) {
            if (app(DemonstrationCleanup::class)->archiveRefusal($order) === null) {
                $archived[] = $order->id;
            }
        }

        return $archived;
    }
}
