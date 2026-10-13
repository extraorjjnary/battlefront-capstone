import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import { setTimeout as delay } from 'node:timers/promises';

// Opt in with a running local app and an existing Chromium/Edge executable.
const baseUrl = process.env.BATTLEFRONT_UI_URL;
const executable = process.env.BATTLEFRONT_UI_BROWSER;
const widths = [320, 375, 390, 428, 768, 1024, 1440];
const longName = 'Battlefront Ultra Performance Graphics Card With Extended Cooling';
const product = {
    id: 7, name: longName, brand: 'Battlefront', category: { id: 3, name: 'Graphics Cards & Accessories' },
    price: '123456.78', discount_price: '99999.99', description: 'Detailed specifications for computer hardware.',
    image_url: 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="200" height="100"%3E%3Crect width="200" height="100" fill="gray"/%3E%3C/svg%3E', inventory: { quantity: 8, status: 'low_stock' }, tags: [], is_featured: true,
};
const cart = {
    items: [{ id: 11, quantity: 2, product: { ...product, category: product.category.name },
        line_total: '199999.98', availability: { status: 'available', available_quantity: 8 } }],
    item_count: 1, total_quantity: 2, total: '199999.98', has_conflicts: false,
};
const order = {
    id: 7, reference: 'BF-000007', created_at: '2026-10-12T08:00:00Z', can_cancel: true,
    status: { value: 'pending', label: 'Pending' }, recipient: { name: 'Customer', contact_number: '09171234567' },
    customer: { name: 'Customer', email: 'customer@example.test' },
    fulfillment: { value: 'pickup', label: 'Pickup' },
    payment: { method: { value: 'cash', label: 'Cash (Pay at Pickup)', requires_proof: false },
        status: { value: 'pending', label: 'Pending' }, notice: 'Payment is collected at pickup.',
        rejection_reasons: [], can_resubmit_proof: false },
    shipment: null, delivery_quote: null, product_subtotal: '199999.98', delivery_fee: '0.00',
    items: [{ id: 11, product, quantity: 2, unit_price: '99999.99', line_total: '199999.98' }],
    item_count: 1, total_quantity: 2, total: '199999.98', cancellation_blocked_reason: null,
};
const checkout = {
    cart, customer: { name: 'Customer', default_delivery_address: null },
    pickupLocation: { name: 'Battlefront Sagay City', address: 'A, E Marañon Street, Poblacion II, Sagay City' },
    fulfillmentMethods: [{ value: 'pickup', label: 'Pickup' }, { value: 'delivery', label: 'Delivery' }],
    paymentMethods: [
        { value: 'cash', label: 'Cash (Pay at Pickup)', available_for: ['pickup'], requires_proof: false },
        { value: 'card_at_store', label: 'Debit/Credit Card (Pay at Store)', available_for: ['pickup'], requires_proof: false },
        ...['gcash', 'maya'].map(value => ({ value, label: value === 'gcash' ? 'GCash' : 'Maya',
            available_for: ['pickup', 'delivery'], requires_proof: true,
            payment_account: { account_name: 'Demo receiving account', account_number: '09XX XXX XXXX', is_demo: true } })),
        { value: 'cod', label: 'Cash on Delivery (COD)', available_for: ['delivery'], requires_proof: false },
    ],
    pickupQuote: { product_subtotal: cart.total, delivery_fee: '0.00', total: cart.total },
    deliveryQuotes: [{ destination: 'Sagay City', carrier: 'lbc', product_subtotal: cart.total,
        delivery_fee: '80.00', total: '200079.98', base_fee: '80.00', handling_surcharge: '0.00',
        preparation_days: 1, packing_expectation: 'Standard packing', is_demo: true,
        assumption_label: 'Battlefront-configured demonstration estimates, not official LBC quotations.',
        notice: 'Battlefront estimates, not live LBC quotations or tracking.',
        estimated_delivery_start: '2026-10-13', estimated_delivery_end: '2026-10-15' }],
    deliveryLocations: [{ code: '1804523000', destination: 'Sagay City', name: 'Sagay City',
        barangays: [{ code: '1804523018', name: 'Poblacion II' }] }],
    deliveryAddressPrefill: { delivery_destination: 'Sagay City', delivery_barangay_code: '1804523018', delivery_address_details: '12 Mabini Street' },
};

async function openBrowser(t) {
    const profile = await mkdtemp(join(tmpdir(), 'battlefront-responsive-'));
    const browser = spawn(executable, [
        '--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
        // Intercepted fixture documents lack the local server's IP metadata.
        '--disable-features=LocalNetworkAccessChecks',
        '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank',
    ], { windowsHide: true, stdio: 'ignore' });
    let socket;
    t.after(async () => {
        if (socket?.readyState === WebSocket.OPEN) socket.close();
        browser.kill();
        await delay(200);
        assert.ok(profile.startsWith(join(tmpdir(), 'battlefront-responsive-')));
        await rm(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 });
    });
    let port;
    for (let attempt = 0; attempt < 100; attempt++) {
        try { port = (await readFile(join(profile, 'DevToolsActivePort'), 'utf8')).split('\n')[0]; break; }
        catch { await delay(100); }
    }
    assert.ok(port, 'The configured browser must expose its debugging port');
    const tabs = await (await fetch(`http://localhost:${port}/json`)).json();
    socket = new WebSocket(tabs.find(tab => tab.type === 'page').webSocketDebuggerUrl);
    await new Promise((resolve, reject) => {
        socket.addEventListener('open', resolve, { once: true });
        socket.addEventListener('error', reject, { once: true });
    });
    let sequence = 0;
    const pending = new Map();
    const exceptions = [];
    const diagnostics = [];
    let intercept;
    socket.addEventListener('message', event => {
        const message = JSON.parse(event.data);
        if (message.id) {
            const request = pending.get(message.id);
            pending.delete(message.id);
            clearTimeout(request.timer);
            if (message.error) request.reject(new Error(JSON.stringify(message.error)));
            else request.resolve(message.result);
        } else if (message.method === 'Fetch.requestPaused') {
            void intercept(message.params).catch(error => {
                if (error.message.includes('Invalid InterceptionId')) diagnostics.push(error.message);
                else exceptions.push(error.message);
            });
        } else if (message.method === 'Runtime.exceptionThrown') {
            exceptions.push(message.params.exceptionDetails.exception?.description ?? message.params.exceptionDetails.text);
        } else if (message.method === 'Log.entryAdded') {
            diagnostics.push(message.params.entry.text);
        } else if (message.method === 'Network.loadingFailed') {
            diagnostics.push(message.params.errorText);
        }
    });
    const command = (method, params = {}) => new Promise((resolve, reject) => {
        const id = ++sequence;
        const timer = setTimeout(() => { pending.delete(id); reject(new Error(`Browser timed out: ${method}`)); }, 15000);
        pending.set(id, { resolve, reject, timer });
        socket.send(JSON.stringify({ id, method, params }));
    });
    const evaluate = async expression => {
        const result = await command('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
        assert.equal(result.exceptionDetails, undefined, JSON.stringify(result.exceptionDetails));
        return result.result.value;
    };
    await command('Page.enable');
    await command('Runtime.enable');
    await command('Log.enable');
    await command('Network.enable');
    await command('Page.navigate', { url: baseUrl });
    for (let attempt = 0; attempt < 100; attempt++) {
        if (await evaluate(`!!document.querySelector('#app')?.children.length`)) break;
        await delay(100);
    }
    const initialPage = await evaluate(`JSON.parse(document.querySelector('script[data-page="app"]').textContent)`);
    const template = await (await fetch(baseUrl)).text();
    let fixture;
    let fixtureNumber = 0;
    intercept = async ({ requestId, request, resourceType, responseStatusCode }) => {
        if (responseStatusCode && resourceType === 'Document' && new URL(request.url).pathname === '/__responsive_fixture') {
            const page = { ...initialPage, component: fixture.component, scrollProps: fixture.component === 'Products/Index' ? { products: { pageName: 'page', currentPage: 1, previousPage: null, nextPage: null } } : {}, props: { ...initialPage.props, ...fixture.props,
                auth: fixture.auth ?? { user: null, can: {} }, errors: {}, notificationSummary: { unread_count: 0, recent: [] }, responsiveFixtureNumber: fixture.number,
            }, url: '/__responsive_fixture' };
            const encoded = JSON.stringify(page).replaceAll('<', '\\u003c');
            const html = template.replace(/(<script data-page="app"[^>]*>)[\s\S]*?(<\/script>)/, `$1${encoded}$2`)
                .replace(/<div data-server-rendered="true" id="app">[\s\S]*?(?=<\/body>)/, '<div id="app"></div>');
            assert.notEqual(html, template, 'Replace the initial Inertia page with an isolated fixture');
            await command('Fetch.fulfillRequest', { requestId, responseCode: 200,
                responseHeaders: [{ name: 'Content-Type', value: 'text/html; charset=utf-8' }], body: Buffer.from(html).toString('base64') });
        } else if (new URL(request.url).pathname.endsWith('/delivery-locations')) {
            const url = new URL(request.url);
            const level = url.searchParams.get('level');
            const parent = url.searchParams.get('parent_code');
            const city = { code: '1804523000', name: 'Sagay City', destination: 'Sagay City', region_code: '1800000000', province_code: '1804500000', type: 'city', city_class: 'CC' };
            const locations = level === 'regions' ? [{ code: '1800000000', name: 'Negros Island Region' }]
                : level === 'provinces' ? [{ code: '1804500000', name: 'Negros Occidental', region_code: '1800000000' }]
                    : level === 'cities' && parent === '1804500000' ? [city]
                        : level === 'city' ? [city]
                            : level === 'barangays' ? checkout.deliveryLocations[0].barangays.map(barangay => ({ ...barangay, city_code: city.code })) : [];
            await command('Fetch.fulfillRequest', { requestId, responseCode: 200,
                responseHeaders: [{ name: 'Content-Type', value: 'application/json' }], body: Buffer.from(JSON.stringify({ data: { version: '2026-03-31.v2', locations } })).toString('base64') });
        } else if (new URL(request.url).pathname.endsWith('/checkout/delivery-quote')) {
            const quote = { ...checkout.deliveryQuotes[0], destination: '1804523000', destination_label: 'Sagay City', payment_methods: ['gcash', 'maya', 'cod'] };
            await command('Fetch.fulfillRequest', { requestId, responseCode: 200,
                responseHeaders: [{ name: 'Content-Type', value: 'application/json' }], body: Buffer.from(JSON.stringify({ data: { available: true, quote } })).toString('base64') });
        } else if (request.method !== 'GET') {
            await command('Fetch.failRequest', { requestId, errorReason: 'Aborted' });
        } else if (new URL(request.url).pathname.endsWith('/notifications/summary')) {
            await command('Fetch.fulfillRequest', { requestId, responseCode: 200,
                responseHeaders: [{ name: 'Content-Type', value: 'application/json' }], body: Buffer.from('{"unread_count":0,"recent":[]}').toString('base64') });
        } else await command('Fetch.continueRequest', { requestId });
    };
    await command('Fetch.enable', { patterns: [{ urlPattern: '*', requestStage: 'Request' }, { urlPattern: '*/__responsive_fixture', requestStage: 'Response' }] });
    return {
        command, evaluate, exceptions, diagnostics,
        async show(component, props, customer = true) {
            fixture = { component, props, number: ++fixtureNumber, auth: customer ? { user: { id: 999999, name: 'Responsive Customer', email: 'fixture@example.test', role: customer === 'admin' ? 'administrator' : 'customer', appearance: 'dark' },
                can: { useCustomerCart: customer !== 'admin', accessAdministration: customer === 'admin' } } : undefined };
            await command('Page.navigate', { url: `${baseUrl}/__responsive_fixture` });
            for (let attempt = 0; attempt < 100; attempt++) {
                if (await evaluate(`!!document.querySelector('#app')?.__vue_app__ && !!document.querySelector('h1') && JSON.parse(document.querySelector('script[data-page="app"]').textContent).props.responsiveFixtureNumber === ${fixture.number}`)) {
                    await evaluate('document.fonts.ready.then(() => true)');
                    return;
                }
                await delay(100);
            }
            assert.fail(`The ${component} fixture did not mount: ${exceptions.join('; ')}; ${diagnostics.slice(-8).join('; ')}`);
        },
        async resize(width, height = 900) {
            await command('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });
            await delay(150);
        },
    };
}

async function assertFits(browser, label) {
    const clipped = await browser.evaluate(`(() => {
        const failures = [];
        for (const element of document.querySelectorAll('main h1, main h2, main h3, main p, main dd, main label, main input:not([type=hidden]), main button, main a, header nav')) {
            const rect = element.getBoundingClientRect();
            if (!rect.width || !rect.height) continue;
            let parent = element.parentElement;
            let scrollable = false;
            while (parent && parent !== document.body) {
                if (['auto', 'scroll'].includes(getComputedStyle(parent).overflowX)) { scrollable = true; break; }
                parent = parent.parentElement;
            }
            if (!scrollable && (rect.left < -1 || rect.right > innerWidth + 1)) failures.push(element.textContent.trim().slice(0,80) || element.id);
        }
        return failures;
    })()`);
    assert.deepEqual(clipped, [], `${label}: content must fit the viewport even when the shell clips overflow`);
}

test('responsive shopping layouts remain usable in a real browser', {
    skip: !baseUrl || !executable ? 'Set BATTLEFRONT_UI_URL and BATTLEFRONT_UI_BROWSER to run browser geometry checks' : false,
    timeout: 180000,
}, async t => {
    const browser = await openBrowser(t);

    await t.test('catalog has two mobile columns with readable prices and responsive image fitting', async () => {
        for (const customer of [false, true]) {
            await browser.show('Products/Index', { products: { data: Array.from({ length: 8 }, (_, index) => ({ ...product, id: index + 1 })), total: 8, current_page: 1, last_page: 1, next_page_url: null, prev_page_url: null },
                filters: { sort: 'featured' }, filter_options: { categories: [], brands: [], tags: [] }, recommendations: [] }, customer);
            for (const width of widths) {
                await browser.resize(width);
                await assertFits(browser, `catalog ${width}`);
                const result = await browser.evaluate(`(() => {
                    const cards = [...document.querySelector('#product-list-heading').closest('section').querySelectorAll('a')];
                    const price = cards[0].querySelector('article > div:last-child');
                    return { columns: cards.filter(card => Math.abs(card.getBoundingClientRect().top-cards[0].getBoundingClientRect().top)<2).length,
                        showcaseAbsent: !document.querySelector('#featured-products-heading'),
                        featuredInteractions: cards.every(card => card.getAttribute('href')?.includes('/products/')
                            && card.textContent.includes('Featured') && card.textContent.includes('Low stock')),
                        sortFits: (() => {
                            const control = document.querySelector('#catalog-sort');
                            const rect = control.getBoundingClientRect();
                            return rect.left >= 0 && rect.right <= innerWidth && control.getAttribute('aria-labelledby')?.includes('catalog-sort-label');
                        })(),
                        priceFits: price.scrollWidth <= price.clientWidth + 1, imageFits: getComputedStyle(cards[0].querySelector('img')).objectFit === 'contain',
                        descriptionHidden: getComputedStyle(cards[0].querySelector('h3 + p')).display === 'none' };
                })()`);
                assert.equal(result.columns, width < 1024 ? 2 : width < 1280 ? 3 : 4, `catalog columns at ${width}`);
                assert.equal(result.showcaseAbsent, true, 'Catalog has no standalone Featured Products showcase');
                assert.equal(result.featuredInteractions, true, 'Main grid products retain Featured badges, navigation and qualitative stock badges');
                assert.equal(result.sortFits, true, `labeled sort control fits at ${width}`);
                assert.equal(result.priceFits, true, `catalog price fits at ${width}`);
                assert.equal(result.imageFits, width < 1024, 'Catalog photography uses containment on mobile and fills the desktop frame');
                assert.equal(result.descriptionHidden, width < 640, 'Mobile cards reserve space for names, prices and stock');
            }
        }
    });

    await t.test('product details keep guest and customer quantity actions within the panel', async () => {
        for (const customer of [false, true]) {
            await browser.show('Products/Show', { product, can_record_product_dwell: false, recommendations: [] }, customer);
            for (const width of widths) {
                await browser.resize(width);
                await assertFits(browser, `product details ${width}`);
                assert.equal(await browser.evaluate(`(() => { const button = document.querySelector('form button[type=submit]'); return button.scrollWidth <= button.clientWidth + 1; })()`), true, `Quantity action text fits at ${width}`);
            }
        }
    });

    await t.test('populated cart and order details fit, and cart removal dialogs scroll', async () => {
        for (const [component, props] of [['Cart/Index', { cart }], ['Orders/Index', { orders: { data: [order], total: 1, current_page: 1, last_page: 1 } }], ['Orders/Show', { order, isConfirmation: false }]]) {
            await browser.show(component, props);
            for (const width of widths) {
                await browser.resize(width);
                await assertFits(browser, `${component} ${width}`);
            }
        }
        await browser.show('Cart/Index', { cart: { ...cart, items: [{ ...cart.items[0], product: { ...cart.items[0].product, name: longName.repeat(12) } }] } });
        await browser.resize(320, 480);
        await browser.evaluate(`[...document.querySelectorAll('button')].find(button => button.textContent.trim() === 'Remove').click()`);
        await delay(300);
        const dialog = await browser.evaluate(`(() => { const el = document.querySelector('[role=dialog]'); const rect = el.getBoundingClientRect(); el.scrollTop = el.scrollHeight; return { top: rect.top, bottom: rect.bottom, right: rect.right, scrolled: el.scrollTop > 0 }; })()`);
        assert.ok(dialog.top >= 0 && dialog.bottom <= 480 && dialog.right <= 320, 'Long dialog fits the available viewport');
        assert.equal(dialog.scrolled, true, 'Dialog actions are reachable by scrolling');
        await browser.evaluate(`document.querySelector('[data-slot=dialog-close]').click()`);
    });

    await t.test('checkout payment choices, wallet proof and totals stay usable on mobile', async () => {
        await browser.show('Checkout/Index', checkout);
        for (const width of widths) {
            await browser.resize(width);
            await assertFits(browser, `checkout pickup ${width}`);
        }
        await browser.resize(320);
        const checkoutState = await browser.evaluate(`(() => ({
            hasDelivery: !!document.querySelector('input[name=fulfillment_method][value=delivery]'),
            heading: document.querySelector('h1')?.textContent,
            text: document.querySelector('main')?.innerText.slice(0, 300),
        }))()`);
        assert.equal(checkoutState.hasDelivery, true, `Checkout fixture must finish mounting: ${JSON.stringify(checkoutState)}; ${browser.exceptions.join('; ')}; ${browser.diagnostics.slice(-5).join('; ')}`);
        await browser.evaluate(`document.querySelector('input[name=fulfillment_method][value=delivery]').click()`);
        await delay(300);
        assert.equal(await browser.evaluate(`document.querySelector('input[name=delivery_region_code]').value`), '1800000000');
        assert.equal(await browser.evaluate(`document.querySelector('input[name=delivery_city_code]').value`), '1804523000');
        for (const value of ['gcash', 'maya', 'cod']) {
            await browser.evaluate(`document.querySelector('input[name=payment_method][value=${value}]').click()`);
            await delay(100);
            await assertFits(browser, `checkout ${value} 320`);
            assert.equal(await browser.evaluate(`!!document.querySelector('input[name=payment_proof]')`), value !== 'cod');
        }
        const sizes = await browser.evaluate(`[...document.querySelectorAll('[data-slot=input], [data-slot=select-trigger], [data-slot=button]')].filter(el => el.getBoundingClientRect().height > 0).map(el => el.getBoundingClientRect().height)`);
        assert.ok(sizes.every(height => height >= 44), 'Mobile form controls have at least 44px height');
    });

    await t.test('navigation opens on phones and uses the desktop sidebar at the tablet breakpoint', async () => {
        await browser.show('Cart/Index', { cart });
        await browser.resize(320);
        await browser.evaluate(`document.querySelector('[data-sidebar=trigger]').click()`);
        await delay(300);
        assert.ok(await browser.evaluate(`!!document.querySelector('[role=dialog]')`), 'Mobile navigation opens');
        await browser.command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
        await delay(400);
        await browser.resize(768);
        await browser.evaluate(`document.querySelector('[data-sidebar=trigger]').click()`);
        await delay(300);
        assert.equal(await browser.evaluate(`!!document.querySelector('[role=dialog]')`), false, '768px toggles the desktop sidebar');
    });

    await t.test('admin panels, charts and horizontally scrollable tables fit without losing desktop density', async () => {
        const fixtures = [
            ['Dashboard/Administration', { dashboard: {
                kpis: { recorded_revenue: '829300.00', recorded_sales: 259, pending_orders: 0, processing_orders: 0, low_stock_products: 1, out_of_stock_products: 1 },
                needs_attention: { pending_payment_reviews: 0, payment_orders: [], low_stock_products: [{ ...product, quantity: 3, reorder_level: 5 }], out_of_stock_products: [product] }, recent_orders: [],
            } }],
            ['Administration/Reports/Sales', {
                filters: { from: '2026-09-01', to: '2026-09-30', period: 'month' }, active_filters: { product: null, category: null },
                kpis: { total_sales: 2, total_revenue: '199999.98', average_order_value: '99999.99', total_items_sold: 2 },
                timeline: { labels: ['Sep 2026'], sales: [2], revenue: [199999.98] }, top_products: { ids: [7], labels: [longName], quantities: [2] },
                categories: { ids: [3], labels: ['Graphics cards'], revenue: [199999.98], rows: [] },
                products: { data: [{ id: 7, name: longName, category: 'Graphics cards', sales_count: 2, quantity_sold: 2, item_revenue: '199999.98' }], total: 1, current_page: 1, last_page: 1 },
                recommendation_engagement: { summary: {}, placements: [] },
            }],
            ['Administration/Customers/Show', { customer: { id: 9, name: 'Customer With A Long Name', email: 'customer.with.a.long.email@example.test', created_at: '2026-10-12T08:00:00Z' } }],
            ['Administration/Orders/Show', { order: { ...order, status: { value: 'processing', label: 'Processing' }, allowed_status_transitions: [],
                payment: { ...order.payment, pay_at_pickup: true, can_confirm_pickup: true } } }],
            ['Administration/Forecasting', { products: { data: [], current_page: 1, last_page: 1 }, filters: {}, timezone: 'Asia/Manila', forecasts: {
                data: [{ id: 1, product: { ...product, product_code: 'PRODUCT123' }, method_label: 'Additive Holt–Winters', forecast_quantity: '123.45', target_label: 'Q1 2027', source_label: '36 completed months', generated_at: '2026-10-12T08:00:00Z' }], current_page: 1, last_page: 1,
            } }],
        ];
        for (const [component, props] of fixtures) {
            await browser.show(component, props, 'admin');
            for (const width of widths) {
                await browser.resize(width);
                await assertFits(browser, `${component} ${width}`);
                assert.equal(await browser.evaluate(`[...document.querySelectorAll('canvas')].every(el => el.getBoundingClientRect().right <= innerWidth + 1)`), true, `Charts fit at ${width}`);
                if (width < 768) {
                    assert.deepEqual(await browser.evaluate(`[...document.querySelectorAll('[data-slot=button]')].filter(el => el.getBoundingClientRect().height && el.scrollHeight > el.clientHeight + 1).map(el => el.textContent.trim())`), [], 'Wrapped action labels fit inside their buttons');
                }
            }
            if (component === 'Administration/Reports/Sales' || component === 'Administration/Forecasting') {
                await browser.resize(320);
                const table = await browser.evaluate(`(() => { const table = document.querySelector('table'); const region = table.parentElement; region.focus(); region.scrollLeft = region.scrollWidth; return { scrolled: region.scrollLeft > 0, focused: document.activeElement === region }; })()`);
                assert.equal(table.scrolled, true, 'Dense tables remain horizontally scrollable');
                assert.equal(table.focused, true, 'Table scrolling is keyboard accessible');
            }
        }
    });

    assert.deepEqual(browser.exceptions, [], 'Fixture pages mount without JavaScript exceptions');
});

test('shopping photos fill desktop frames and recommendations keep two usable mobile columns', {
    skip: !baseUrl || !executable ? 'Set BATTLEFRONT_UI_URL and BATTLEFRONT_UI_BROWSER to run browser geometry checks' : false,
    timeout: 180000,
}, async t => {
    const browser = await openBrowser(t);
    const photos = [[240, 240], [320, 120], [120, 320], [240, 240]].map(([width, height], index) => ({
        ...product,
        id: index + 1,
        image_url: `data:image/svg+xml,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}"><rect width="100%" height="100%" fill="gray"/><rect x="1" y="1" width="${width - 2}" height="${height - 2}" fill="none" stroke="red" stroke-width="2"/></svg>`)}`,
    }));
    const recommendations = photos.map((product, index) => ({
        product: { ...product, inventory: { ...product.inventory, status: ['in_stock', 'low_stock', 'out_of_stock', 'in_stock'][index] } },
        reasons: [{ code: 'popular', value: 'Similar to a product you viewed' }],
    }));
    const imageItems = photos.map((product, index) => ({ ...cart.items[0], id: index + 1, product: { ...product, category: product.category.name } }));
    const pages = [
        ['Products/Index', { products: { data: photos, total: 4, current_page: 1, last_page: 1, next_page_url: null, prev_page_url: null }, filters: { q: null, category_id: null, brand: null, tag_id: null }, filter_options: { categories: [], brands: [], tags: [] }, recommendations: [] }, false],
        ['Products/Index', { products: { data: photos, total: 4, current_page: 1, last_page: 1, next_page_url: null, prev_page_url: null }, filters: { q: null, category_id: null, brand: null, tag_id: null }, filter_options: { categories: [], brands: [], tags: [] }, recommendations }, true],
        ['Products/Show', { product: photos[1], can_record_product_dwell: false, recommendations }, false],
        ['Products/Show', { product: photos[1], can_record_product_dwell: false, recommendations }, true],
        ['Dashboard/Customer', { dashboard: { summary: { cart_items: 0, cart_units: 0, active_orders: 0, total_orders: 0 }, latest_order: null }, recommendations }, true],
        ['Cart/Index', { cart: { ...cart, items: imageItems, item_count: 4, total_quantity: 8, total: '799999.92' }, recommendations }, true],
        ['Orders/Show', { order: { ...order, item_count: 4, total_quantity: 8, product_subtotal: '799999.92', total: '799999.92', items: photos.map((product, index) => ({ ...order.items[0], id: index + 1, product })) }, isConfirmation: false }, true],
    ];
    const measurePhotos = `(() => [...document.querySelectorAll('main img[src^="data:image/svg+xml"]')].map(image => {
        const rect = image.getBoundingClientRect();
        const frameElement = image.parentElement.parentElement;
        const bounds = frameElement.getBoundingClientRect();
        const style = getComputedStyle(frameElement);
        const border = { left: parseFloat(style.borderLeftWidth), right: parseFloat(style.borderRightWidth), top: parseFloat(style.borderTopWidth), bottom: parseFloat(style.borderBottomWidth) };
        const frame = { left: bounds.left + border.left, right: bounds.right - border.right, top: bounds.top + border.top, bottom: bounds.bottom - border.bottom,
            width: bounds.width - border.left - border.right, height: bounds.height - border.top - border.bottom };
        const scale = Math.min(rect.width / image.naturalWidth, rect.height / image.naturalHeight);
        const width = image.naturalWidth * scale;
        const height = image.naturalHeight * scale;
        const idealScale = Math.min(frame.width / image.naturalWidth, frame.height / image.naturalHeight);
        return { recommendation: !!image.closest('[data-recommendation-product-id]'),
            catalog: !!image.closest('section[aria-labelledby="product-list-heading"]'), fit: getComputedStyle(image).objectFit, loaded: image.naturalWidth > 0,
            matchesDetailsHeight: Math.abs(bounds.bottom - (frameElement.nextElementSibling?.getBoundingClientRect().bottom ?? bounds.bottom)) < 0.75,
            edgeToEdge: Math.abs(rect.left - frame.left) < 0.75 && Math.abs(rect.right - frame.right) < 0.75 && Math.abs(rect.top - frame.top) < 0.75 && Math.abs(rect.bottom - frame.bottom) < 0.75,
            visible: rect.left + (rect.width - width) / 2 >= frame.left - 0.75 && rect.right - (rect.width - width) / 2 <= frame.right + 0.75 && rect.top + (rect.height - height) / 2 >= frame.top - 0.75 && rect.bottom - (rect.height - height) / 2 <= frame.bottom + 0.75,
            fillsFrame: scale >= idealScale * 0.97, frameWidth: frame.width, frameHeight: frame.height };
    }))()`;
    for (const [component, props, customer] of pages) {
        await browser.show(component, props, customer);
        await browser.evaluate(`Promise.all([...document.querySelectorAll('main img[src^="data:image/svg+xml"]')].map(image => { image.loading = 'eager'; return image.decode(); }))`);
        for (const width of widths) {
            await browser.resize(width);
            await assertFits(browser, `${component} photography ${width}`);
            const images = await browser.evaluate(measurePhotos);
            assert.ok(images.length >= 3, `${component} renders the image fixtures`);
            const isDesktopCatalog = component === 'Products/Index' && width >= 1024;
            for (const image of images) {
                const fillsDesktopFrame = width >= 1024 && (image.recommendation || ['Products/Index', 'Products/Show'].includes(component));
                assert.equal(image.loaded, true);
                assert.equal(image.fit, fillsDesktopFrame ? 'cover' : 'contain', `${component} uses the appropriate photo presentation at ${width}`);
                if (fillsDesktopFrame) {
                    assert.equal(image.edgeToEdge, true, `${component} desktop photography reaches every edge of its frame at ${width}`);
                } else {
                    assert.equal(image.visible, true, `${component} photo fits its image frame at ${width}`);
                }
                assert.equal(image.fillsFrame, true, `${component} avoids unnecessary image padding at ${width}`);
                if (component === 'Products/Show' && width >= 1024 && !image.recommendation) {
                    assert.equal(image.matchesDetailsHeight, true, 'Desktop detail photography fills the row beside product information');
                }
            }
            if (props.recommendations?.length) {
                const cards = await browser.evaluate(`(() => {
                    const cards = [...document.querySelectorAll('[data-recommendation-product-id]')];
                    if (!cards.length) return { columns: 0, usable: false, clickable: false, hideHeight: 0, titlesCompact: false };
                    return { columns: getComputedStyle(cards[0].parentElement).gridTemplateColumns.split(' ').length,
                        usable: cards.every(card => {
                            const bounds = card.getBoundingClientRect();
                            return [...card.querySelectorAll('p, a, button, span')].every(element => {
                                const rect = element.getBoundingClientRect();
                                return rect.left >= bounds.left && rect.right <= bounds.right && element.scrollWidth <= element.clientWidth + 1;
                            });
                        }),
                        clickable: cards.every(card => [...card.querySelectorAll('a')].every(link => link.getAttribute('href')?.includes('/products/'))),
                        titlesCompact: cards.every(card => { const title = card.querySelectorAll('a')[1]; return title.getBoundingClientRect().height <= parseFloat(getComputedStyle(title).lineHeight) * 2 + 0.75; }),
                        hideHeight: cards[0].querySelector('button').getBoundingClientRect().height };
                })()`);
                assert.equal(cards.columns, width < 1024 ? 2 : width < 1280 ? 3 : 4, `${component} recommendation columns at ${width}`);
                assert.equal(cards.usable, true, `${component} recommendation titles, prices and stock labels fit at ${width}`);
                assert.equal(cards.clickable, true, 'Recommendation images and titles link to their products');
                assert.ok(cards.hideHeight >= (width < 768 ? 44 : 40), 'Recommendation hide actions retain usable targets');
                if (width < 640) assert.equal(cards.titlesCompact, true, 'Mobile recommendation names stay within two readable lines');
                const photo = images.find(image => image.recommendation);
                assert.ok(Math.abs(photo.frameWidth / photo.frameHeight - (width < 640 ? 1 : 1.5)) < 0.01, 'Recommendation images match catalog aspect ratios');
            }
            if (component === 'Products/Index') {
                const cards = await browser.evaluate(`(() => {
                    const cards = [...document.querySelector('#product-list-heading').closest('section').querySelectorAll('a')];
                    return { columns: cards.filter(card => Math.abs(card.getBoundingClientRect().top - cards[0].getBoundingClientRect().top) < 2).length,
                        clickable: cards.every(card => card.getAttribute('href')?.includes('/products/')) };
                })()`);
                assert.equal(cards.columns, width < 1024 ? 2 : width < 1280 ? 3 : 4);
                assert.equal(cards.clickable, true);
                const photo = images.find(image => image.catalog);
                assert.ok(Math.abs(photo.frameWidth / photo.frameHeight - (width < 640 ? 1 : 1.5)) < 0.01, 'Catalog image aspect ratios are preserved');
                const point = await browser.evaluate(`(() => { const image = document.querySelector('#product-list-heading').closest('section').querySelector('img'); image.scrollIntoView({block:'center'}); const rect = image.getBoundingClientRect(); return {x:rect.left+rect.width/2,y:rect.top+rect.height/2}; })()`);
                await browser.command('Input.dispatchMouseEvent', { type: 'mouseMoved', ...point });
                await delay(350);
                const hovered = (await browser.evaluate(measurePhotos)).find(image => image.catalog);
                assert.ok(await browser.evaluate(`parseFloat(getComputedStyle(document.querySelector('#product-list-heading').closest('section').querySelector('img').parentElement).scale) > 1`), 'Catalog hover zoom remains active');
                if (!isDesktopCatalog) {
                    assert.equal(hovered.visible, true, 'Mobile hover keeps the full photograph inside the frame');
                }
                assert.equal(hovered.frameWidth, photo.frameWidth, 'Hover preserves card width');
                assert.equal(hovered.frameHeight, photo.frameHeight, 'Hover preserves image height');
                await browser.command('Input.dispatchMouseEvent', { type: 'mouseMoved', x: 0, y: 0 });
                await delay(350);
            }
        }
    }
    await browser.show('Products/Show', { product: photos[1], can_record_product_dwell: false, recommendations }, true);
    await browser.resize(320);
    await browser.evaluate(`document.querySelector('[data-recommendation-product-id] button').click()`);
    assert.equal(await browser.evaluate(`document.querySelectorAll('[data-recommendation-product-id]').length`), 3, 'Mobile Hide removes only the selected suggestion');
    await assertFits(browser, 'recommendations after dismissal at 320');
    if (process.env.BATTLEFRONT_UI_PREVIEW) {
        await browser.command('Page.navigate', { url: `${baseUrl}/products` });
        for (let attempt = 0; attempt < 100; attempt++) {
            if (await browser.evaluate(`location.pathname === '/products' && !!document.querySelector('#app')?.__vue_app__ && !!document.querySelector('#product-list-heading')`)) break;
            await delay(100);
        }
        for (const width of [375, 1440]) {
            await browser.resize(width);
            await browser.evaluate(`(() => { const section = document.querySelector('#product-list-heading').closest('section'); scrollTo(0, section.getBoundingClientRect().top + scrollY - 88); return Promise.allSettled([...section.querySelectorAll('img')].slice(0,8).map(image => { image.loading = 'eager'; return image.decode(); })); })()`);
            await delay(350);
            const screenshot = await browser.command('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
            await writeFile(`${process.env.BATTLEFRONT_UI_PREVIEW}-${width}.png`, Buffer.from(screenshot.data, 'base64'));
        }
        const liveProducts = await browser.evaluate(`JSON.parse(document.querySelector('script[data-page="app"]').textContent).props.products.data`);
        await browser.show('Products/Show', {
            product: { ...product, ...liveProducts[0] }, can_record_product_dwell: false,
            recommendations: liveProducts.slice(0, 4).map(product => ({ product, reasons: [{ code: 'similar', value: 'Similar to a product you viewed' }] })),
        }, true);
        for (const width of [375, 1440]) {
            await browser.resize(width);
            await browser.evaluate(`Promise.allSettled([...document.querySelectorAll('main img')].map(image => { image.loading = 'eager'; return image.decode(); }))`);
            for (const [name, selector] of [['detail', 'main > article'], ['recommendations', '#recommendation-section-heading']]) {
                await browser.evaluate(`(() => { const element = document.querySelector('${selector}'); scrollTo(0, element.getBoundingClientRect().top + scrollY - 88); })()`);
                await delay(350);
                const screenshot = await browser.command('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
                await writeFile(`${process.env.BATTLEFRONT_UI_PREVIEW}-${name}-${width}.png`, Buffer.from(screenshot.data, 'base64'));
            }
        }
    }
    assert.deepEqual(browser.exceptions, []);
});
