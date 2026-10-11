<?php

use App\Models\Forecast;
use App\Models\Product;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\Process;

afterEach(function () {
    $this->travelBack();
});

test('generated and saved forecast views round units without changing decimal contracts or chart data', function (string $quantity, string $display) {
    $this->travelTo('2026-10-15 12:00:00');
    config(['app.timezone' => 'UTC', 'forecasting.operational_coverage' => []]);
    $product = Product::factory()->create();
    $forecast = Forecast::factory()->for($product)->create(['predicted_demand' => $quantity, 'method' => 'additive_holt_winters']);
    $response = $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.forecasting.index', ['product_id' => $product->id]));
    $response->assertInertia(fn (Assert $page) => $page->where('forecasts.data.0.forecast_quantity', $quantity));
    $props = $response->inertiaProps();
    $generated = [
        'status' => 'ready', 'product' => $props['selected_product'], 'forecast_quantity' => $quantity,
        'target_label' => 'Q4 2026', 'source_label' => 'October 2023 - September 2026',
        'generated_at' => '2026-10-15T12:00:00Z', 'timezone' => 'UTC', 'is_synthetic' => false,
        'message' => "Estimated quarterly demand: $quantity units for Q4 2026, based on 36 completed months of recorded sales.",
        'guidance' => 'Use this estimate alongside business judgment.', 'sales_scope_label' => 'Captured system transactions',
        'observations' => [['start' => '2026-09-01', 'label' => 'Sep 2026', 'quantity_sold' => 7]],
        'monthly_forecasts' => array_map(fn (string $month): array => [
            'start' => "2026-$month-01", 'label' => "2026-$month", 'forecast_quantity' => $quantity,
        ], ['10', '11', '12']),
    ];
    $script = <<<'JS'
        import { readFileSync } from 'node:fs';
        import { resolve } from 'node:path';
        import { createServer } from 'vite';
        import vue from '@vitejs/plugin-vue';
        import { createInertiaApp } from '@inertiajs/vue3';
        import { createSSRApp, h } from 'vue';
        import { renderToString } from 'vue/server-renderer';
        const input = JSON.parse(readFileSync(0, 'utf8'));
        const server = await createServer({
            configFile: false, logLevel: 'silent',
            server: { middlewareMode: true, hmr: false, watch: null },
            optimizeDeps: { noDiscovery: true },
            resolve: { alias: { '@': resolve('resources/js'), 'vue-chartjs': 'virtual:forecast-chart' } },
            plugins: [vue(), {
                name: 'capture-forecast-chart',
                resolveId(id) { if (id === 'virtual:forecast-chart') return id; },
                load(id) {
                    if (id === 'virtual:forecast-chart') return 'export let chart; export const Line = { props: ["data", "options"], setup(props) { chart = props; return () => null; } };';
                },
            }],
        });
        try {
            const page = await server.ssrLoadModule('/resources/js/pages/Administration/Forecasting.vue');
            const rendered = await createInertiaApp({
                page: { component: 'Administration/Forecasting', props: input.props, flash: { forecast_result: input.generated }, url: '/administration/forecasting', version: null },
                render: renderToString, resolve: () => page,
                setup: ({ App, props, plugin }) => createSSRApp({ render: () => h(App, props) }).use(plugin),
            });
            const { chart } = await server.ssrLoadModule('virtual:forecast-chart');
            process.stdout.write(JSON.stringify({
                html: rendered.body,
                monthlyData: chart.data.datasets[1].data.slice(-3),
                historicalData: chart.data.datasets[0].data[0],
                tooltip: chart.options.plugins.tooltip.callbacks.label({ dataset: { label: 'Forecast' }, parsed: { y: Number(input.generated.forecast_quantity) } }),
                zeroTick: chart.options.scales.y.ticks.callback(0),
                originalQuantity: input.generated.forecast_quantity,
            }));
        } finally { await server.close(); }
        JS;
    $process = new Process(['node', '--input-type=module', '-e', $script], base_path());
    $process->setInput(json_encode(['props' => $props, 'generated' => $generated], JSON_THROW_ON_ERROR));
    $process->setTimeout(30);

    $process->mustRun();

    $rendered = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    $visible = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($rendered['html'])));
    expect($visible)->toContain("Estimated quarterly demand: $display units", "$display units", 'whole units for display');
    expect($rendered['html'])->toMatch('/<td[^>]*>\s*'.preg_quote($display, '/').'\s*<\/td>/');
    expect($rendered['monthlyData'])->toEqual(array_fill(0, 3, (float) $quantity));
    expect($rendered['historicalData'])->toBe(7);
    expect($rendered['tooltip'])->toBe("Forecast: $display units");
    expect($rendered['zeroTick'])->toBe('0');
    expect($rendered['originalQuantity'])->toBe($quantity);
    expect($forecast->fresh()->predicted_demand)->toBe($quantity);
})->with([
    'round up' => ['5.89', '6'], 'round down' => ['5.49', '5'],
    'half up' => ['5.50', '6'], 'zero' => ['0.00', '0'],
]);
