<?php

namespace Tests;

use App\Models\User;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\ParallelTesting;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $parallelTesting = $app->make(ParallelTesting::class);

        if ($parallelTesting->token() === false) {
            $parallelTesting->resolveTokenUsing(static fn (): string => 'process-'.getmypid());
        }

        return $app;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function fakeIsolatedStorage(string $disk, array $config = []): LocalFilesystemAdapter
    {
        $fake = Storage::fake($disk.'-process-'.getmypid().'-'.bin2hex(random_bytes(8)), array_replace(
            (array) config('filesystems.disks.'.$disk, []),
            $config,
        ));
        Storage::set($disk, $fake);

        return $fake;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        gc_collect_cycles();
    }

    protected function recordLegacyRefundPayment(int $refundId, User $recorder, string $amount, string $method, string $reference): int
    {
        $refund = DB::table('customer_refunds')->where('id', $refundId)->firstOrFail();
        $id = DB::table('customer_refund_payments')->insertGetId(['customer_refund_id' => $refundId, 'recorded_by' => $recorder->id,
            'amount' => $amount, 'payment_method' => $method, 'external_reference' => $reference, 'paid_at' => now()]);
        DB::table('customer_refunds')->where('id', $refundId)->update(['paid_amount' => bcadd((string) $refund->paid_amount, $amount, 2),
            'recorded_by' => $recorder->id, 'payment_method' => $method, 'external_reference' => $reference, 'paid_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
