<?php

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('audit export protects spreadsheet cells containing staff supplied text', function () {
    $owner = User::factory()->administrator()->create();
    DB::table('audit_logs')->insert([
        'actor_id' => $owner->id,
        'action' => 'test.export',
        'subject_type' => 'supplier_return',
        'subject_id' => 123,
        'reason' => '  =HYPERLINK("bad")',
        'changes' => json_encode(['reference' => '=CMD()'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
    ]);

    $response = $this->actingAs($owner)->get(route('administration.reports.audit.export'));

    $response->assertOk()->assertStreamed()->assertDownload('audit-log.csv');
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, $response->streamedContent());
    rewind($stream);
    fgetcsv($stream, 0, ',', '"', '');
    $row = fgetcsv($stream, 0, ',', '"', '');
    fclose($stream);

    expect($row[6])->toBe("'  =HYPERLINK(\"bad\")");
});

test('audit events show readable changes only to a role allowed to view the record', function () {
    $owner = User::factory()->administrator()->create();
    $it = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'it')->value('id'),
    ]);
    DB::table('audit_logs')->insert([
        'actor_id' => $owner->id,
        'action' => 'product.updated',
        'subject_type' => 'product',
        'subject_id' => 123,
        'reason' => 'Price correction',
        'changes' => json_encode(['before' => ['price' => '100'], 'after' => ['price' => '120', 'proof_path' => 'private/file.jpg']], JSON_THROW_ON_ERROR),
        'created_at' => now(),
    ]);

    $this->actingAs($owner)->get(route('administration.reports.audit', ['action' => 'product.updated']))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 1)
            ->where('entries.data.0.action_label', 'Product Updated')
            ->where('entries.data.0.details.0.before', '100')
            ->where('entries.data.0.details.0.after', '120')
            ->where('entries.data.0.details.1.after', '[redacted]')
            ->where('entries.data.0.reason', 'Price correction'));
    $this->actingAs($it)->get(route('administration.reports.audit', ['action' => 'product.updated']))
        ->assertInertia(fn (Assert $page) => $page->where('entries.data.0.details_visible', false)
            ->where('entries.data.0.details', [])
            ->where('entries.data.0.reason', null)
            ->where('entries.data.0.subject_url', null));
});

test('product activation is recorded once and a repeated submission leaves history unchanged', function () {
    $owner = User::factory()->administrator()->create();
    $product = Product::factory()->create(['is_active' => true]);

    $this->actingAs($owner)->patch(route('administration.products.activation.update', $product), [
        'is_active' => false,
    ])->assertSessionHasNoErrors();
    $this->actingAs($owner)->patch(route('administration.products.activation.update', $product), [
        'is_active' => false,
    ])->assertSessionHasNoErrors();

    expect($product->fresh()->is_active)->toBeFalse();
    expect(DB::table('audit_logs')->where('subject_type', 'product')->where('subject_id', $product->id)
        ->where('action', 'product.deactivated')->count())->toBe(1);
});

test('audit screen and export use the same date actor action and subject filters', function () {
    $owner = User::factory()->administrator()->create();
    DB::table('audit_logs')->insert([
        ['actor_id' => $owner->id, 'action' => 'stock.checked', 'subject_type' => 'inventory', 'subject_id' => 7,
            'reason' => 'Count verified', 'created_at' => '2026-09-10 10:00:00'],
        ['actor_id' => $owner->id, 'action' => 'stock.checked', 'subject_type' => 'inventory', 'subject_id' => 8,
            'reason' => 'Other item', 'created_at' => '2026-09-10 10:00:00'],
    ]);
    $filters = ['from' => '2026-09-10', 'to' => '2026-09-10', 'actor_id' => $owner->id,
        'action' => 'stock.checked', 'subject_type' => 'inventory', 'subject_id' => 7];

    $this->actingAs($owner)->get(route('administration.reports.audit', $filters))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 1)
            ->where('entries.data.0.reason', 'Count verified'));
    $csv = $this->actingAs($owner)->get(route('administration.reports.audit.export', $filters))
        ->assertOk()->streamedContent();
    expect($csv)->toContain('Count verified')->not->toContain('Other item');
});
