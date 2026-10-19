<?php

use App\Models\Product;
use App\Models\User;
use App\Notifications\ApprovalConcentrationAlert;
use App\Services\ApprovalMonitor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

test('the fifth manager approval for the same staff member raises an owner review alert', function () {
    $owner = User::factory()->administrator()->create();
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $manager = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'manager')->value('id'),
    ]);
    for ($number = 1; $number <= 4; $number++) {
        DB::table('audit_logs')->insert([
            'actor_id' => $manager->id, 'action' => 'inventory.adjustment.approved',
            'subject_type' => 'inventory_adjustment_request', 'subject_id' => $number,
            'changes' => json_encode(['requester_id' => $staff->id], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    app(ApprovalMonitor::class)->afterApproval($manager, $staff->id);
    $this->assertDatabaseMissing('audit_logs', ['action' => 'approval.concentration_alert']);

    $product = Product::factory()->create();
    $damageId = DB::table('damage_reports')->insertGetId([
        'product_id' => $product->id, 'requester_id' => $staff->id, 'quantity' => 2,
        'status' => 'decided', 'reason' => 'Physical inspection damage',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('audit_logs')->insert([
        'actor_id' => $manager->id, 'action' => 'damage.decided',
        'subject_type' => 'damage_report', 'subject_id' => $damageId,
        'changes' => json_encode(['requester_id' => $staff->id], JSON_THROW_ON_ERROR),
        'created_at' => now(),
    ]);
    app(ApprovalMonitor::class)->afterApproval($manager, $staff->id);
    $this->assertDatabaseHas('audit_logs', ['action' => 'approval.concentration_alert', 'subject_id' => $staff->id]);

    $payload = (new ApprovalConcentrationAlert($manager->name, $staff->name, 5))->toArray($manager);
    expect($payload['body'])->toContain('5 requests')->toContain($staff->name);
    $notification = $owner->notifications()->firstOrFail();
    expect($notification->data['decisions'][0])->toMatchArray([
        'action' => 'damage.decided', 'subject_id' => $damageId, 'quantity' => 2,
    ]);
});

test('owner notification failures do not escape and do not prevent alerts to other owners', function () {
    $firstOwner = User::factory()->administrator()->create();
    $secondOwner = User::factory()->administrator()->create();
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $manager = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'manager')->value('id'),
    ]);
    for ($number = 1; $number <= 5; $number++) {
        DB::table('audit_logs')->insert([
            'actor_id' => $manager->id,
            'action' => 'inventory.adjustment.approved',
            'subject_type' => 'inventory_adjustment_request',
            'subject_id' => $number,
            'changes' => json_encode(['requester_id' => $staff->id], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    $attempts = 0;
    Notification::shouldReceive('send')->twice()->andReturnUsing(function () use (&$attempts): void {
        $attempts++;
        if ($attempts === 1) {
            throw new RuntimeException('Notification store unavailable.');
        }
    });

    app(ApprovalMonitor::class)->afterApproval($manager, $staff->id);

    expect($attempts)->toBe(2);
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'approval.concentration_alert',
        'subject_id' => $staff->id,
    ]);
});
