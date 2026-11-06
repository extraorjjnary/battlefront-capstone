<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DemonstrationBaseline
{
    public function __construct(private readonly DemonstrationProvisioning $provisioning) {}

    /** @return array<string, mixed> */
    public function inspect(): array
    {
        if (! $this->provisioning->enabled()
            || config('demonstration.provisioning_enabled') !== false
            || config('catalog-demo.provisioning_enabled') !== false
            || config('services.paymongo.mode') !== 'test' || config('services.paymongo.enabled') !== false) {
            throw new RuntimeException('Exact demo target and disclosure are required; disable provisioning and gateway starts, retaining test mode.');
        }

        $connection = DB::connection()->getName();
        foreach (['queue.connections.database.connection', 'queue.failed.database', 'queue.batching.database',
            'session.connection', 'cache.stores.database.connection', 'cache.stores.database.lock_connection'] as $key) {
            if (config($key) !== null && config($key) !== $connection) {
                throw new RuntimeException('Queue, session and cache connections must use the baseline database.');
            }
        }
        if (config('queue.default') !== 'database' || config('session.driver') !== 'database'
            || config('cache.default') !== 'database' || config('queue.connections.database.table') !== 'jobs'
            || config('session.table') !== 'sessions' || config('cache.stores.database.table') !== 'cache'
            || ! in_array(config('cache.stores.database.lock_table'), [null, 'cache_locks'], true)
            || config('queue.failed.driver') !== 'database-uuids' || config('queue.failed.table') !== 'failed_jobs'
            || config('queue.batching.table') !== 'job_batches') {
            throw new RuntimeException('Baseline validation requires the standard database queue, session and cache stores.');
        }

        $emptyTables = ['sessions', 'personal_access_tokens', 'email_otp_challenges', 'password_reset_tokens',
            'jobs', 'failed_jobs', 'job_batches', 'cache', 'cache_locks', 'paymongo_flows', 'paymongo_attempts',
            'paymongo_operations', 'paymongo_webhook_inbox', 'carts', 'cart_items', 'guest_recommendation_profiles',
            'customer_searches', 'customer_product_views', 'recommendation_interactions', 'wishlist_items',
            'wishlist_activities', 'notifications', 'push_devices', 'notification_push_deliveries', 'pc_builds',
            'pc_build_events', 'inventory_adjustment_requests', 'damage_reports', 'supplier_returns',
            'customer_returns', 'customer_return_requests', 'order_cancellation_requests', 'administration_evidence'];
        foreach ($emptyTables as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException("Baseline contains transient or operational records: {$table}.");
            }
        }
        $accounts = $this->provisioning->accounts();
        if (User::query()->count() !== count($accounts)) {
            throw new RuntimeException('Baseline requires exactly the 34 provisioned demo identities.');
        }
        foreach ($accounts as $email => $account) {
            $user = User::query()->where('email', $email)->first();
            if ($user === null || ! $user->is_active || ! $user->hasVerifiedEmail()
                || $user->name !== $account['name'] || $user->remember_token !== null
                || $user->role !== ($account['role'] === null ? UserRole::Customer : UserRole::Administrator)
                || $user->administrationRoleCode() !== $account['role']
                || ! DemonstrationProvisioning::owns('user', $user->id)) {
                throw new RuntimeException('Demo identity, verification, role or ownership differs from the approved roster.');
            }
        }

        if (DB::table('products')->count() !== 654 || DB::table('products')->where('is_catalog_imported', true)->count() !== 654
            || DB::table('inventories')->count() !== 654 || DB::table('inventory_movements')->count() !== 654) {
            throw new RuntimeException('Baseline requires the complete catalog and original initialization movements.');
        }
        $stock = [0, 0, 0];
        foreach (DB::table('inventories')->get() as $inventory) {
            $audits = DB::table('audit_logs')->where('action', 'catalog.demo_inventory.provisioned')
                ->where('subject_type', 'product')->where('subject_id', $inventory->product_id)->get();
            $audit = $audits->count() === 1 ? $audits->first() : null;
            $values = $audit === null ? [] : json_decode($audit->changes, true, flags: JSON_THROW_ON_ERROR);
            $movement = DB::table('inventory_movements')->where('product_id', $inventory->product_id)->first();
            if (($values['profile'] ?? null) !== 'retailer-demo-v1'
                || ($values['quantity'] ?? null) !== (int) $inventory->quantity
                || ($values['reorder_level'] ?? null) !== (int) $inventory->reorder_level
                || (int) $inventory->quarantined_quantity !== 0 || $movement === null
                || $movement->kind !== 'demo_initialization' || $movement->reference_type !== 'audit_log'
                || (int) $movement->reference_id !== (int) $audit?->id
                || (int) $movement->sellable_before !== 0 || (int) $movement->sellable_after !== (int) $inventory->quantity
                || (int) $movement->sellable_delta !== (int) $inventory->quantity
                || (int) $movement->quarantined_before !== 0 || (int) $movement->quarantined_delta !== 0
                || (int) $movement->quarantined_after !== 0) {
                throw new RuntimeException('Inventory differs from audited original demo stock.');
            }
            $stock[(int) $inventory->quantity === 0 ? 2 : ($inventory->quantity <= $inventory->reorder_level ? 1 : 0)]++;
        }
        if ($stock !== [650, 3, 1]) {
            throw new RuntimeException('Original demo stock must have the 650/3/1 availability profile.');
        }
        $coverage = $this->provisioning->coverage();
        if ($coverage === [] || DB::table('orders')->doesntExist() || DB::table('branches')->count() !== 5
            || DB::table('chatbot_knowledge')->count() < 9) {
            throw new RuntimeException('Audited hosted synthetic history and enabled coverage are required.');
        }
        foreach (DB::table('orders')->get() as $order) {
            if (! str_starts_with((string) $order->development_fixture_key, HostedDemoHistory::FIXTURE_PREFIX)
                || ! DemonstrationProvisioning::owns('order', $order->id) || $order->payment_origin === 'paymongo') {
                throw new RuntimeException('Baseline contains an ordinary or gateway order.');
            }
        }

        $tables = [];
        $names = Schema::getTableListing(schemaQualified: false);
        sort($names, SORT_STRING);
        foreach ($names as $table) {
            $rows = [];
            foreach (DB::table($table)->cursor() as $row) {
                $data = (array) $row;
                ksort($data);
                $rows[] = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            }
            sort($rows, SORT_STRING);
            $tables[$table] = ['count' => count($rows), 'sha256' => hash('sha256', implode("\n", $rows))];
        }
        $objects = [];
        foreach (['public', 'local'] as $diskName) {
            $disk = Storage::disk($diskName);
            $paths = $disk->allFiles();
            sort($paths, SORT_STRING);
            foreach ($paths as $path) {
                $stream = $disk->readStream($path);
                if (! is_resource($stream)) {
                    throw new RuntimeException('A baseline storage object cannot be read.');
                }
                try {
                    $hash = hash_init('sha256');
                    if (hash_update_stream($hash, $stream) !== $disk->size($path)) {
                        throw new RuntimeException('A baseline storage object cannot be hashed.');
                    }
                    $objects[$diskName][$path] = hash_final($hash);
                } finally {
                    fclose($stream);
                }
            }
        }
        foreach (DB::table('products')->pluck('image_path') as $path) {
            if (! is_string($path) || ! isset($objects['public'][$path])) {
                throw new RuntimeException('Every catalog product requires a readable baseline public image.');
            }
        }

        return ['version' => 1, 'driver' => DB::connection()->getDriverName(), 'timezone' => config('app.timezone'),
            'commerce_timezone' => config('battlefront.timezone'), 'tables' => $tables, 'objects' => $objects,
            'schema_sha256' => $this->schemaFingerprint($names), 'coverage' => $coverage];
    }

    /** @param list<string> $tables */
    private function schemaFingerprint(array $tables): string
    {
        $definitions = [];
        if (DB::connection()->getDriverName() === 'mysql') {
            foreach ($tables as $table) {
                $quoted = DB::connection()->getQueryGrammar()->wrapTable($table);
                $row = (array) DB::selectOne('SHOW CREATE TABLE '.$quoted);
                $definitions[] = preg_replace('/AUTO_INCREMENT=\\d+/', 'AUTO_INCREMENT', $row['Create Table']);
            }
            $triggers = DB::select('SHOW TRIGGERS');
            $required = ['orders_no_delete', 'sales_no_update', 'sales_no_delete', 'audit_logs_no_update',
                'audit_logs_no_delete', 'inventory_movements_no_update', 'inventory_movements_no_delete'];
            if (array_diff($required, array_map(fn (\stdClass $trigger): string => $trigger->Trigger, $triggers)) !== []) {
                throw new RuntimeException('Required MySQL history protections are missing or cannot be inspected.');
            }
            usort($triggers, fn (\stdClass $a, \stdClass $b): int => strcmp($a->Trigger, $b->Trigger));
            foreach ($triggers as $trigger) {
                $definitions[] = [$trigger->Trigger, $trigger->Event, $trigger->Table, $trigger->Statement, $trigger->Timing];
            }
        } elseif (DB::connection()->getDriverName() === 'sqlite') {
            $definitions = DB::table('sqlite_master')->whereNotNull('sql')->where('name', 'not like', 'sqlite_%')->orderBy('name')->pluck('sql')->all();
        } else {
            throw new RuntimeException('Only MySQL and isolated SQLite tests support baseline inspection.');
        }

        return hash('sha256', json_encode($definitions, JSON_THROW_ON_ERROR));
    }
}
