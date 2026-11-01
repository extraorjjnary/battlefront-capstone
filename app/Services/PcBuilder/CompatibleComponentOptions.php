<?php

namespace App\Services\PcBuilder;

use App\Models\User;
use Illuminate\Validation\ValidationException;

class CompatibleComponentOptions
{
    public function __construct(private readonly PcBuilderService $builder, private readonly CompatibilityEngine $engine) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function execute(array $input, ?User $customer = null): array
    {
        $catalog = $this->builder->catalog($customer);
        $products = collect($catalog)->flatten(1)->keyBy('id');
        $selection = array_map(fn (array $item): array => ['key' => $item['key'], 'product_id' => (int) $item['product_id'], 'quantity' => (int) $item['quantity'], 'configuration' => $item['configuration'] ?? []], $input['components']);
        $removeKeys = $input['remove_keys'] ?? [];
        if (array_diff($removeKeys, array_column($selection, 'key')) !== []) {
            throw ValidationException::withMessages(['remove_keys' => 'Remove only parts present in the current selection.']);
        }
        $candidates = array_filter($catalog[$input['component_type']] ?? [], fn (array $product): bool => (! isset($input['candidate_id']) || $product['id'] === (int) $input['candidate_id'])
            && (($input['search'] ?? '') === '' || str_contains(mb_strtolower($product['name'].' '.($product['brand'] ?? '')), mb_strtolower($input['search'])))
            && (($input['brand'] ?? '') === '' || $product['brand'] === $input['brand']));
        $options = [];
        foreach ($candidates as $candidate) {
            $multiple = ComponentTypes::definitions()[$candidate['component_type']]['multiple'];
            $proposed = array_values(array_filter($selection, fn (array $item): bool => ! in_array($item['key'], $removeKeys, true)
                && ($multiple || ($products->get($item['product_id'])['component_type'] ?? null) !== $candidate['component_type'])));
            $existingIndex = array_search($candidate['id'], array_column($proposed, 'product_id'), true);
            $existing = $existingIndex === false ? null : ($proposed[$existingIndex] ?? null);
            $quantity = isset($input['quantity']) ? (int) $input['quantity'] : (($existing['quantity'] ?? 0) + 1);
            if ($existingIndex !== false && $existing !== null) {
                $candidateKey = $existing['key'];
                $proposed[$existingIndex] = ['key' => $candidateKey, 'product_id' => $candidate['id'], 'quantity' => $quantity,
                    'configuration' => $input['candidate_configuration'] ?? $existing['configuration']];
            } else {
                $candidateKey = 'candidate-'.$candidate['id'];
                $suffix = 0;
                while (in_array($candidateKey, array_column($proposed, 'key'), true)) {
                    $candidateKey = 'candidate-'.$candidate['id'].'-'.++$suffix;
                }
                $proposed[] = ['key' => $candidateKey, 'product_id' => $candidate['id'], 'quantity' => $quantity, 'configuration' => $input['candidate_configuration'] ?? []];
            }
            $resolved = [];
            $reasons = [];
            if (count($proposed) > 32 || $quantity > 99) {
                $reasons[] = 'A build supports at most 32 selections and 99 units per selection.';
            }
            $demand = collect($proposed)->groupBy('product_id')->map(fn ($items): int => $items->sum('quantity'));
            foreach ($proposed as $item) {
                $product = $products->get($item['product_id']);
                if ($product === null) {
                    $reasons[] = 'A selected product is no longer available. Remove it from the build.';

                    continue;
                }
                if ($demand->get($product['id']) > (int) $product['stock_quantity']) {
                    $reasons[] = $product['name'].': insufficient stock for the selected quantity.';
                }
                $resolved[] = [
                    'key' => $item['key'], 'product_id' => $product['id'], 'quantity' => (int) $item['quantity'], 'configuration' => $item['configuration'],
                    'name' => $product['name'], 'component_type' => $product['component_type'],
                    'specifications' => $product['specifications'], 'specifications_reviewed' => $product['selection_issues'] === [],
                    'evidence' => $product['evidence'],
                ];
            }
            $validation = $this->engine->evaluate($resolved, true);
            $reasons = array_values(array_unique([...$reasons, ...$candidate['selection_issues'], ...$validation['purchase_blockers']]));
            $affected = [];
            foreach ($validation['findings'] as $finding) {
                if ($finding['scope'] === 'selected_parts' && $finding['status'] !== 'compatible') {
                    $affected = [...$affected, ...$finding['component_keys']];
                }
            }
            $affected = array_values(array_intersect(array_unique($affected), array_column($proposed, 'key'), array_column($selection, 'key')));
            $standalone = $this->engine->evaluate(array_values(array_filter($resolved, fn (array $part): bool => $part['key'] === $candidateKey)), true);
            $options[] = [
                ...$candidate, 'selectable' => $reasons === [], 'selection_reasons' => $reasons,
                'affected_keys' => $affected,
                'can_resolve' => ! $multiple && $candidate['selection_issues'] === [] && $standalone['can_purchase'] && $affected !== [] && (int) $candidate['stock_quantity'] >= $quantity,
                'proposed_components' => $proposed,
            ];
        }
        $sort = $input['sort'] ?? 'name';
        usort($options, function (array $left, array $right) use ($sort): int {
            return ($right['selectable'] <=> $left['selectable']) ?: ($sort === 'name' ? strnatcasecmp($left['name'], $right['name']) :
                ((float) $left['price'] <=> (float) $right['price']) * ($sort === 'price_desc' ? -1 : 1));
        });
        $page = (int) ($input['page'] ?? 1);

        return ['products' => array_slice($options, ($page - 1) * 20, 20), 'total' => count($options), 'page' => $page,
            'last_page' => max(1, (int) ceil(count($options) / 20)), 'selection_version' => $input['selection_version'],
            'strict_selection_enabled' => (bool) config('pc-builder.strict_selection_enabled')];
    }

    /** @return array<string, mixed> */
    public function readiness(): array
    {
        $catalog = $this->builder->catalog();
        $products = collect($catalog)->flatten(1)->keyBy('id');
        $templates = app(PcBuildTemplateService::class)->forCatalog($catalog);
        $verifiedTemplate = null;
        foreach ($templates as $template) {
            $parts = [];
            foreach ($template['components'] as $item) {
                $product = $products->get($item['product_id']);
                $parts[] = ['key' => $item['key'], 'product_id' => $product['id'], 'component_type' => $product['component_type'], 'quantity' => $item['quantity'],
                    'configuration' => $item['configuration'] ?? [], 'specifications' => $product['specifications'], 'specifications_reviewed' => $product['selection_issues'] === []];
            }
            $validation = $this->engine->evaluate($parts, true);
            if ($validation['complete'] && $validation['can_purchase']) {
                $verifiedTemplate = $template['uuid'];
                break;
            }
        }

        return [
            'ready' => $verifiedTemplate !== null,
            'verified_template_uuid' => $verifiedTemplate,
            'strict_selection_enabled' => (bool) config('pc-builder.strict_selection_enabled'),
            'eligible_products' => $products->count(),
            'verified_products' => $products->filter(fn (array $product): bool => $product['selection_issues'] === [])->count(),
            'missing_types' => array_keys(array_filter($catalog, fn (array $options): bool => ! collect($options)->contains(fn (array $product): bool => $product['selection_issues'] === []))),
        ];
    }
}
