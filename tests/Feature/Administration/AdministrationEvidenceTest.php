<?php

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

test('inspection evidence stays private and is limited to five files per case', function () {
    Storage::fake('local');
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();
    $this->actingAs($staff)->post(route('administration.damage-reports.store'), [
        'product_id' => $product->id, 'quantity' => 1, 'reason_code' => 'handling_damage', 'reason' => 'Damaged packaging found',
    ])->assertSessionHasNoErrors();
    $damageId = DB::table('damage_reports')->where('product_id', $product->id)->value('id');

    $this->actingAs($staff)->post(route('administration.evidence.store', [
        'subjectType' => 'damage_report', 'subjectId' => $damageId,
    ]), ['files' => [UploadedFile::fake()->create('inspection.pdf', 10, 'application/pdf')]])->assertSessionHasNoErrors();

    $file = DB::table('administration_evidence')->where('subject_type', 'damage_report')->where('subject_id', $damageId)->first();
    Storage::disk('local')->assertExists($file->path);
    expect(fn () => DB::table('administration_evidence')->where('id', $file->id)->update(['original_name' => 'altered.pdf']))->toThrow(QueryException::class);
    $this->actingAs($staff)->get(route('administration.evidence.show', $file->id))->assertOk()->assertDownload('inspection.pdf');
    $this->actingAs($customer)->get(route('administration.evidence.show', $file->id))->assertForbidden();
    $this->actingAs($staff)->post(route('administration.evidence.store', [
        'subjectType' => 'damage_report', 'subjectId' => $damageId,
    ]), ['files' => [UploadedFile::fake()->create('script.php', 10, 'text/plain')]])->assertSessionHasErrors('files.0');
    $this->actingAs($staff)->post(route('administration.evidence.store', [
        'subjectType' => 'damage_report', 'subjectId' => $damageId,
    ]), ['files' => [
        UploadedFile::fake()->create('two.pdf', 10, 'application/pdf'),
        UploadedFile::fake()->create('three.pdf', 10, 'application/pdf'),
        UploadedFile::fake()->create('four.pdf', 10, 'application/pdf'),
        UploadedFile::fake()->create('five.pdf', 10, 'application/pdf'),
        UploadedFile::fake()->create('six.pdf', 10, 'application/pdf'),
    ]])->assertSessionHasErrors('files');
    $this->assertDatabaseCount('administration_evidence', 1);
});
