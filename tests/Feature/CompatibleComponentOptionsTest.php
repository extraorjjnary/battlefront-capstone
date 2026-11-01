<?php

use App\Enums\FulfillmentMethod;
use App\Enums\PaymentMethod;
use App\Models\ComponentSpecificationRevision;
use App\Models\Product;
use App\Models\ProductComponentProfile;
use App\Models\User;
use App\Services\Order\OrderPlacementService;
use App\Services\PcBuilder\CompatibleComponentOptions;
use App\Services\PcBuilder\PcBuildTemplateService;
use App\Services\PcBuilder\PcBuildToCartService;
use App\Services\PcBuilder\SavedPcBuildService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

test('options check candidates against selected parts and retain disabled reasons', function () {
    $cpu = optionProduct('processor');
    $compatible = optionProduct('motherboard');
    $conflict = optionProduct('motherboard', ['socket' => 'AM5']);
    $draft = optionProduct('motherboard', [], false);
    $unavailable = optionProduct('motherboard', ['socket' => 'AM5']);
    $unavailable->inventory()->update(['quantity' => 0]);
    $response = $this->postJson(route('pc-builder.options'), optionPayload('motherboard', [$cpu]));
    $response->assertOk()->assertJsonPath('selection_version', 7)->assertJsonPath('products.0.id', $compatible->id)->assertJsonPath('products.0.selectable', true);
    $options = collect($response->json('products'))->keyBy('id');
    expect($options[$conflict->id]['selectable'])->toBeFalse()
        ->and($options[$conflict->id]['selection_reasons'])->not->toBeEmpty()
        ->and($options[$draft->id]['selectable'])->toBeFalse()
        ->and($options[$unavailable->id]['selectable'])->toBeFalse()
        ->and($options[$unavailable->id]['can_resolve'])->toBeFalse();
});

test('an approved label with incomplete attributes or wrong model evidence is not selectable', function () {
    $cpu = optionProduct('processor', ['maximum_power_w' => null]);
    $this->postJson(route('pc-builder.options'), optionPayload('processor'))->assertOk()->assertJsonPath('products.0.selectable', false);
    $board = optionProduct('motherboard');
    $profile = $board->componentProfile()->with('revisions')->firstOrFail();
    $original = $profile->revisions->first();
    $revision = $profile->revisions()->create(['revision' => 2, 'schema_version' => 1, 'specifications' => $original->specifications, 'evidence' => [['url' => 'https://manufacturer.example/other', 'variant' => 'Other model', 'section' => 'Specifications', 'verified_at' => '2026-11-01']], 'review_status' => 'approved']);
    $profile->update(['current_revision' => $revision->revision]);
    $this->postJson(route('pc-builder.options'), optionPayload('motherboard'))->assertOk()->assertJsonPath('products.0.selectable', false);
});

test('strict purchases allow missing future parts but block unknown selected facts', function () {
    config(['pc-builder.strict_selection_enabled' => true]);
    $customer = User::factory()->customer()->create();
    $cpu = optionProduct('processor');
    $this->postJson(route('pc-builder.validate'), ['components' => optionPayload('processor', [$cpu])['components']])->assertOk()
        ->assertJsonPath('complete', false)->assertJsonPath('selection_compatible', true)->assertJsonPath('can_purchase', true);
    $draft = optionProduct('memory', [], false);
    $payload = optionPayload('memory', [$cpu, $draft]);
    $this->postJson(route('pc-builder.validate'), ['components' => $payload['components']])->assertOk()->assertJsonPath('can_purchase', false);
    $build = app(SavedPcBuildService::class)->create($customer, ['name' => 'Preserved legacy draft', 'mode' => 'simple', 'components' => $payload['components']]);
    $this->actingAs($customer)->postJson(route('pc-builds.cart.store', $build->uuid))->assertUnprocessable();
    $this->assertDatabaseCount('cart_items', 0);
    expect($build->items()->count())->toBe(2);
});

test('replacement previews remove only explicitly supplied keys and evaluate the retained build', function () {
    $cpu = optionProduct('processor');
    $board = optionProduct('motherboard');
    $replacement = optionProduct('processor', ['socket' => 'AM5']);
    $payload = [...optionPayload('processor', [$cpu, $board]), 'candidate_id' => $replacement->id];
    $this->postJson(route('pc-builder.options'), $payload)->assertOk()->assertJsonPath('products.0.selectable', false)->assertJsonPath('products.0.can_resolve', true);
    $response = $this->postJson(route('pc-builder.options'), [...$payload, 'remove_keys' => ['part-'.$board->id]])
        ->assertOk()->assertJsonPath('products.0.selectable', true)->assertJsonCount(1, 'products.0.proposed_components');
    expect($response->json('products.0.proposed_components.0.product_id'))->toBe($replacement->id);
    $this->assertDatabaseCount('pc_build_items', 0);
    $this->postJson(route('pc-builder.options'), [...$payload, 'remove_keys' => ['not-selected']])->assertUnprocessable()->assertJsonValidationErrors('remove_keys');
});

test('selection order does not bypass memory or cooler clearance checks', function () {
    $memory = optionProduct('memory', ['memory_generation' => 'DDR5']);
    $board = optionProduct('motherboard');
    $this->postJson(route('pc-builder.options'), optionPayload('motherboard', [$memory]))->assertOk()->assertJsonPath('products.0.selectable', false);
    $case = optionProduct('case', ['maximum_cpu_cooler_height_mm' => 100]);
    $cooler = optionProduct('cooler', ['height_mm' => 150]);
    $this->postJson(route('pc-builder.options'), optionPayload('cooler', [$case]))->assertOk()->assertJsonPath('products.0.selectable', false);
});

test('fan choices require a verified mounting position and supported size before selection', function () {
    $case = optionProduct('case', ['fan_mounting_positions' => ['front'], 'fan_mounting_capacity' => ['front' => 2], 'fan_mounts' => [['position' => 'front', 'sizes_mm' => [120]]]]);
    $fan = optionProduct('fan');
    $payload = [...optionPayload('fan', [$case]), 'candidate_id' => $fan->id];
    $this->postJson(route('pc-builder.options'), $payload)->assertOk()->assertJsonPath('products.0.selectable', false);
    $this->postJson(route('pc-builder.options'), [...$payload, 'candidate_configuration' => ['mounting_position' => 'front']])->assertOk()->assertJsonPath('products.0.selectable', true);
    $smallFan = optionProduct('fan', ['size_mm' => 80]);
    $this->postJson(route('pc-builder.options'), [...$payload, 'candidate_id' => $smallFan->id, 'candidate_configuration' => ['mounting_position' => 'front']])->assertOk()->assertJsonPath('products.0.selectable', false);
});

test('strict candidates check motherboard power connectors and cooler RAM clearance', function () {
    $board = optionProduct('motherboard');
    $psu = optionProduct('power_supply', ['connectors' => ['ATX24' => 1]]);
    $this->postJson(route('pc-builder.options'), [...optionPayload('power_supply', [$board]), 'candidate_id' => $psu->id])->assertOk()->assertJsonPath('products.0.selectable', false);
    $memory = optionProduct('memory', ['height_mm' => 60]);
    $cooler = optionProduct('cooler');
    $this->postJson(route('pc-builder.options'), [...optionPayload('cooler', [$memory]), 'candidate_id' => $cooler->id])->assertOk()->assertJsonPath('products.0.selectable', false);
});

test('quantity proposals check shared slots and aggregate repeated SKU stock', function () {
    $board = optionProduct('motherboard');
    $memory = optionProduct('memory');
    $memory->inventory()->update(['quantity' => 5]);
    $payload = [...optionPayload('memory', [$board, $memory]), 'quantity' => 3];
    $this->postJson(route('pc-builder.options'), $payload)->assertOk()->assertJsonPath('products.0.selectable', false);
    $stringPayload = [...optionPayload('memory', [$board, $memory]), 'candidate_id' => (string) $memory->id];
    $stringPayload['components'][1]['product_id'] = (string) $memory->id;
    $stringPayload['components'][1]['quantity'] = '1';
    $this->postJson(route('pc-builder.options'), $stringPayload)->assertOk()->assertJsonCount(2, 'products.0.proposed_components')->assertJsonPath('products.0.proposed_components.1.quantity', 2);
    $memory->inventory()->update(['quantity' => 1]);
    $this->postJson(route('pc-builder.options'), optionPayload('memory', [$board, $memory]))->assertOk()->assertJsonPath('products.0.selectable', false);
    $fastMemory = optionProduct('memory', ['speed_mts' => 4000]);
    $this->postJson(route('pc-builder.options'), [...optionPayload('memory', [$board]), 'candidate_id' => $fastMemory->id])->assertOk()->assertJsonPath('products.0.selectable', false);
});

test('options reject browser supplied specifications and support mobile guest contracts', function () {
    $cpu = optionProduct('processor');
    $payload = optionPayload('processor', [$cpu]);
    $payload['components'][0]['specifications'] = ['socket' => 'fabricated'];
    $this->postJson(route('pc-builder.options'), $payload)->assertUnprocessable()->assertJsonValidationErrors('components.0');
    $this->postJson('/api/v1/pc-builder/options', optionPayload('processor'))->assertOk()->assertJsonPath('data.selection_version', 7);
    $this->withToken('invalid')->postJson('/api/v1/pc-builder/options', optionPayload('processor'))->assertUnauthorized();
});

test('strict order placement rechecks evidence without discarding existing cart groups', function () {
    config(['pc-builder.strict_selection_enabled' => true]);
    $customer = User::factory()->customer()->create();
    $cpu = optionProduct('processor');
    $build = app(SavedPcBuildService::class)->create($customer, ['name' => 'CPU upgrade', 'mode' => 'simple', 'components' => optionPayload('processor', [$cpu])['components']]);
    $group = app(PcBuildToCartService::class)->add($customer, $build->uuid);
    $stock = $cpu->inventory->quantity;
    $cpu->componentProfile()->update(['review_status' => 'draft']);
    expect(fn () => app(OrderPlacementService::class)->execute($customer, [
        'cart_item_ids' => $group->items->modelKeys(), 'recipient_name' => 'Upgrade customer', 'contact_number' => '+639171234567',
        'fulfillment_method' => FulfillmentMethod::Pickup, 'delivery_address' => null, 'payment_method' => PaymentMethod::Cash, 'payment_proof_path' => null,
    ]))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('pc_build_cart_groups', 1);
    expect($cpu->inventory()->firstOrFail()->quantity)->toBe($stock);
});

test('release readiness requires a complete sourced in-stock template and does not approve products', function () {
    expect(app(CompatibleComponentOptions::class)->readiness()['ready'])->toBeFalse();
    $parts = array_map(fn (string $type): Product => optionProduct($type), ['processor', 'motherboard', 'memory', 'storage', 'power_supply', 'case']);
    $administrator = User::factory()->administrator()->create();
    app(PcBuildTemplateService::class)->save($administrator, ['name' => 'Reviewed test build', 'is_active' => true, 'components' => optionPayload('processor', $parts)['components']]);
    expect(app(CompatibleComponentOptions::class)->readiness()['ready'])->toBeTrue();
    $parts[0]->inventory()->update(['quantity' => 0]);
    expect(app(CompatibleComponentOptions::class)->readiness()['ready'])->toBeFalse();
});

test('candidate evaluation uses bounded queries and paginates filtered catalog results', function () {
    for ($index = 0; $index < 23; $index++) {
        optionProduct('processor');
    }
    DB::enableQueryLog();
    $result = app(CompatibleComponentOptions::class)->execute([...optionPayload('processor'), 'page' => 2, 'sort' => 'price']);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect(count($queries))->toBeLessThanOrEqual(6)
        ->and($result['products'])->toHaveCount(3)
        ->and($result['total'])->toBe(23)
        ->and($result['last_page'])->toBe(2);
});

/** @param list<Product> $parts
 * @return array<string, mixed>
 */
function optionPayload(string $type, array $parts = []): array
{
    return ['component_type' => $type, 'selection_version' => 7, 'components' => array_map(fn (Product $product): array => ['key' => 'part-'.$product->id, 'product_id' => $product->id, 'quantity' => 1], $parts)];
}

/** @param array<string, mixed> $overrides */
function optionProduct(string $type, array $overrides = [], bool $approved = true): Product
{
    $facts = [
        'processor' => ['model' => 'Test CPU', 'socket' => 'AM4', 'generation' => 'Ryzen 5000', 'supported_chipsets' => ['B450'], 'maximum_power_w' => 65, 'integrated_graphics' => true, 'included_cooler' => true, 'required_power_connectors' => []],
        'motherboard' => ['cpu_support' => [['model' => 'Test CPU', 'minimum_bios_version' => '1.0']], 'socket' => 'AM4', 'chipset' => 'B450', 'supported_cpu_generations' => ['Ryzen 5000'], 'bios_version' => '1.0', 'memory_generations' => ['DDR4'], 'dimm_slots' => 2, 'maximum_memory_gb' => 64, 'maximum_memory_speed_mts' => 3200, 'ecc_supported' => false, 'registered_memory_supported' => false, 'form_factor' => 'mATX', 'pcie_x16_slots' => 1, 'display_outputs' => ['HDMI'], 'storage_slots' => [['interfaces' => ['SATA'], 'form_factors' => ['2.5']]], 'fan_headers' => [], 'power_draw_w' => 30],
        'memory' => ['memory_generation' => 'DDR4', 'capacity_gb' => 8, 'modules_per_package' => 1, 'speed_mts' => 3200, 'ecc' => false, 'registered' => false, 'power_draw_w' => 3],
        'storage' => ['interface' => 'SATA', 'form_factor' => '2.5', 'power_draw_w' => 5],
        'power_supply' => ['continuous_power_w' => 550, 'connectors' => [], 'form_factor' => 'ATX', 'length_mm' => 140, 'power_draw_w' => 0],
        'case' => ['motherboard_form_factors' => ['mATX'], 'maximum_gpu_length_mm' => 300, 'maximum_cpu_cooler_height_mm' => 160, 'supported_psu_form_factors' => ['ATX'], 'maximum_psu_length_mm' => 180, 'power_draw_w' => 0],
        'cooler' => ['mounting_sockets' => ['AM4'], 'height_mm' => 150, 'radiator_size_mm' => 0, 'power_draw_w' => 5],
        'fan' => ['size_mm' => 120, 'power_draw_w' => 3, 'header_type' => '4pin'],
    ];
    if ($type === 'motherboard') {
        $facts[$type]['required_power_connectors'] = ['ATX24' => 1, 'EPS8' => 1];
    }
    if ($type === 'power_supply') {
        $facts[$type]['connectors'] = ['ATX24' => 1, 'EPS8' => 1];
    }
    if ($type === 'memory') {
        $facts[$type]['height_mm'] = 30;
    }
    if ($type === 'cooler') {
        $facts[$type]['ram_clearance_mm'] = 40;
    }
    $product = Product::factory()->available()->create();
    $partNumber = 'TEST-'.$product->id;
    $status = $approved ? 'approved' : 'draft';
    $profile = ProductComponentProfile::factory()->for($product)->create(['component_type' => $type, 'manufacturer_part_number' => $partNumber, 'review_status' => $status, 'current_revision' => 1]);
    ComponentSpecificationRevision::factory()->for($profile, 'profile')->create(['revision' => 1, 'review_status' => $status, 'specifications' => [...$facts[$type], ...$overrides],
        'evidence' => [['url' => 'https://manufacturer.example/specification', 'title' => 'Exact model', 'variant' => $partNumber, 'section' => 'Specifications', 'verified_at' => '2026-11-01']]]);

    return $product;
}
