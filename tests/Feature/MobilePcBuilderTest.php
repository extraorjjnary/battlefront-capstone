<?php

use App\Enums\AppearancePreference;
use App\Models\ComponentSpecificationRevision;
use App\Models\Inventory;
use App\Models\PcBuildTemplate;
use App\Models\Product;
use App\Models\ProductComponentProfile;
use App\Models\User;
use App\Services\PcBuilder\PcBuildTemplateService;
use App\Services\PcBuilder\SavedPcBuildService;
use Illuminate\Support\Facades\Auth;

test('guest mobile builder returns reviewed catalog facts and rejects invalid bearer credentials', function () {
    $product = mobileBuilderProduct('processor', ['socket' => 'AM5']);
    $this->getJson('/api/v1/pc-builder')->assertOk()->assertJsonPath('data.components.processor.0.id', $product->id)
        ->assertJsonPath('data.components.processor.0.specifications_reviewed', true)
        ->assertJsonPath('data.components.processor.0.specifications.socket', 'AM5');
    Auth::forgetGuards();
    $this->withToken('invalid')->getJson('/api/v1/pc-builder')->assertUnauthorized();
});

test('mobile builder refuses administrator tokens and web sessions cannot save builds', function () {
    $admin = User::factory()->administrator()->create();
    $this->withToken($admin->createToken('phone')->plainTextToken)->getJson('/api/v1/pc-builder')->assertForbidden();
    Auth::forgetGuards();
    $this->withHeaders(['Authorization' => ''])->actingAs(User::factory()->customer()->create())
        ->postJson('/api/v1/pc-builds', [])->assertUnauthorized();
});

test('mobile validation retains unknown and incompatible findings from the shared engine', function () {
    $cpu = mobileBuilderProduct('processor', ['socket' => 'AM5']);
    $board = mobileBuilderProduct('motherboard', ['socket' => 'LGA1700']);
    $this->postJson('/api/v1/pc-builder/validate', ['components' => [
        ['key' => 'cpu', 'product_id' => $cpu->id, 'quantity' => 1],
        ['key' => 'board', 'product_id' => $board->id, 'quantity' => 1],
    ]])->assertOk()->assertJsonPath('data.complete', false)->assertJsonFragment(['status' => 'incompatible']);
    $this->postJson('/api/v1/pc-builder/validate', ['components' => []])->assertOk()
        ->assertJsonPath('data.complete', false)->assertJsonFragment(['status' => 'unknown']);
    $this->postJson('/api/v1/pc-builder/validate', ['components' => [['key' => 'cpu', 'product_id' => $cpu->id, 'quantity' => 0]]])
        ->assertUnprocessable()->assertJsonValidationErrors('components.0.quantity');
});

test('mobile saved builds support editing duplication sharing revocation and deletion', function () {
    $customer = User::factory()->customer()->create();
    $product = mobileBuilderProduct('processor', ['socket' => 'AM5']);
    $payload = ['name' => 'My portable build', 'mode' => 'advanced', 'budget' => '50000.00', 'components' => [['key' => 'cpu', 'product_id' => $product->id, 'quantity' => 1]]];
    $this->withToken($customer->createToken('phone')->plainTextToken);
    $build = $this->postJson('/api/v1/pc-builds', $payload)->assertCreated()->json('data');
    $this->assertDatabaseHas('pc_builds', ['uuid' => $build['uuid'], 'user_id' => $customer->id, 'name' => $payload['name']]);
    $base = '/api/v1/pc-builds/'.$build['uuid'];
    $this->getJson('/api/v1/pc-builds')->assertOk()->assertJsonCount(1, 'data');
    $this->patchJson($base, $payload)->assertUnprocessable()->assertJsonValidationErrors('edit_version');
    $this->patchJson($base, [...$payload, 'name' => 'Updated', 'edit_version' => $build['edit_version']])->assertOk()->assertJsonPath('data.edit_version', $build['edit_version'] + 1);
    $this->patchJson($base, [...$payload, 'edit_version' => $build['edit_version']])->assertUnprocessable()->assertJsonValidationErrors('edit_version');
    $copy = $this->postJson($base.'/duplicate')->assertCreated()->json('data.uuid');
    expect($copy)->not->toBe($build['uuid']);
    $url = $this->postJson($base.'/share')->assertOk()->json('data.url');
    $token = basename($url);
    $this->getJson('/api/v1/pc-builds/shared/'.$token)->assertOk()->assertJsonPath('data.name', 'Updated')->assertJsonMissingPath('data.user_id');
    $this->deleteJson($base.'/share')->assertOk()->assertJsonPath('data.shared', false);
    $this->getJson('/api/v1/pc-builds/shared/'.$token)->assertNotFound();
    $this->deleteJson($base)->assertOk();
    $this->assertDatabaseMissing('pc_builds', ['uuid' => $build['uuid']]);
});

test('mobile saved builds remain private to their owner for every operation', function () {
    $owner = User::factory()->customer()->create();
    $other = User::factory()->customer()->create();
    $build = app(SavedPcBuildService::class)->create($owner, ['name' => 'Private build', 'mode' => 'simple', 'components' => []]);
    $this->withToken($other->createToken('phone')->plainTextToken);
    $base = '/api/v1/pc-builds/'.$build->uuid;
    $this->getJson('/api/v1/pc-builds')->assertOk()->assertJsonCount(0, 'data');
    $this->patchJson($base, ['name' => 'Stolen', 'mode' => 'simple', 'edit_version' => 1, 'components' => []])->assertNotFound();
    $this->deleteJson($base)->assertNotFound();
    $this->postJson($base.'/duplicate')->assertNotFound();
    $this->postJson($base.'/share')->assertNotFound();
    $this->deleteJson($base.'/share')->assertNotFound();
    $this->postJson($base.'/cart')->assertNotFound();
});

test('a complete mobile build enters cart atomically and group selection and removal match web', function () {
    $customer = User::factory()->customer()->create();
    $components = mobileCompleteBuild();
    $this->postJson('/api/v1/pc-builder/validate', ['components' => $components])->assertOk()->assertJsonPath('data.complete', true);
    $this->withToken($customer->createToken('phone')->plainTextToken);
    $build = $this->postJson('/api/v1/pc-builds', ['name' => 'Complete PC', 'mode' => 'simple', 'components' => $components])->assertCreated()->json('data');
    $base = '/api/v1/pc-builds/'.$build['uuid'];
    $this->postJson($base.'/cart')->assertCreated()->assertJsonPath('data.item_count', 6);
    $cart = $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.items.0.pc_build_group.name', 'Complete PC')->json('data');
    $firstId = $cart['items'][0]['id'];
    $this->patchJson('/api/v1/cart/items/'.$firstId, ['quantity' => 2])->assertUnprocessable();
    $this->getJson('/api/v1/checkout?cart_item_ids[]='.$firstId)->assertUnprocessable();
    $this->deleteJson('/api/v1/cart/items/'.$firstId)->assertOk()->assertJsonCount(0, 'data.items');
    Inventory::query()->where('product_id', $components[0]['product_id'])->update(['quantity' => 0]);
    $this->postJson($base.'/cart')->assertUnprocessable();
    $this->assertDatabaseCount('cart_items', 0);
});

test('mobile builder does not publish inactive or unverified templates', function () {
    PcBuildTemplate::factory()->create(['is_active' => false]);
    PcBuildTemplate::factory()->create(['is_active' => true]);
    $this->getJson('/api/v1/pc-builder')->assertOk()->assertJsonCount(0, 'data.templates');
});

test('mobile builder publishes complete reviewed templates using the shared current catalog', function () {
    $components = mobileCompleteBuild();
    $administrator = User::factory()->administrator()->create();
    $template = app(PcBuildTemplateService::class)->save($administrator, ['name' => 'Verified template', 'is_active' => true, 'components' => $components]);

    $this->getJson('/api/v1/pc-builder')->assertOk()->assertJsonCount(1, 'data.templates')
        ->assertJsonPath('data.templates.0.uuid', $template->uuid)->assertJsonCount(6, 'data.templates.0.components');
});

test('mobile appearance saves share the existing customer preference and reject system', function () {
    $user = User::factory()->customer()->create(['appearance' => AppearancePreference::System]);
    $this->withToken($user->createToken('phone')->plainTextToken)->getJson('/api/v1/profile')->assertJsonPath('data.appearance', 'light');
    $this->patchJson('/api/v1/profile', ['name' => $user->name, 'appearance' => 'dark'])->assertOk()->assertJsonPath('data.appearance', 'dark');
    expect($user->fresh()->appearance)->toBe(AppearancePreference::Dark);
    $this->patchJson('/api/v1/profile', ['name' => $user->name, 'appearance' => 'system'])->assertUnprocessable()->assertJsonValidationErrors('appearance');
});

/** @param array<string, mixed> $facts */
function mobileBuilderProduct(string $type, array $facts): Product
{
    $product = Product::factory()->available()->create();
    $profile = ProductComponentProfile::factory()->for($product)->create(['component_type' => $type, 'review_status' => 'approved']);
    ComponentSpecificationRevision::factory()->for($profile, 'profile')->create(['revision' => 1, 'review_status' => 'approved', 'specifications' => $facts]);
    $profile->update(['current_revision' => 1]);

    return $product;
}

/** @return list<array<string, mixed>> */
function mobileCompleteBuild(): array
{
    $facts = [
        'processor' => ['socket' => 'AM5', 'generation' => 'Zen 4', 'supported_chipsets' => ['B650'], 'maximum_power_w' => 65, 'integrated_graphics' => true, 'included_cooler' => true, 'required_power_connectors' => []],
        'motherboard' => ['socket' => 'AM5', 'chipset' => 'B650', 'supported_cpu_generations' => ['Zen 4'], 'bios_version' => '1.0', 'display_outputs' => ['HDMI'], 'memory_generations' => ['DDR5'], 'dimm_slots' => 2, 'maximum_memory_gb' => 64, 'maximum_memory_speed_mts' => 6000, 'ecc_supported' => false, 'registered_memory_supported' => false, 'form_factor' => 'ATX', 'storage_slots' => [['interfaces' => ['NVMe'], 'form_factors' => ['M.2 2280'], 'maximum_length_mm' => 80]], 'fan_headers' => [], 'power_draw_w' => 30],
        'memory' => ['memory_generation' => 'DDR5', 'capacity_gb' => 16, 'modules_per_package' => 2, 'speed_mts' => 5600, 'ecc' => false, 'registered' => false, 'power_draw_w' => 5],
        'storage' => ['interface' => 'NVMe', 'form_factor' => 'M.2 2280', 'length_mm' => 80, 'power_draw_w' => 5],
        'power_supply' => ['continuous_power_w' => 550, 'connectors' => [], 'form_factor' => 'ATX', 'length_mm' => 140, 'power_draw_w' => 0],
        'case' => ['motherboard_form_factors' => ['ATX'], 'supported_psu_form_factors' => ['ATX'], 'maximum_psu_length_mm' => 180, 'power_draw_w' => 0],
    ];

    return collect($facts)->map(function (array $specifications, string $type): array {
        $product = mobileBuilderProduct($type, $specifications);

        return ['key' => $type, 'product_id' => $product->id, 'quantity' => 1];
    })->values()->all();
}
