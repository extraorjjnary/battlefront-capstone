import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { computed, reactive } from 'vue';

function navigationHarness(administrator = true) {
    const page = reactive({
        props: { auth: { can: { accessAdministration: administrator } } },
    });
    const controller = (path) => ({
        index: (options) => ({
            url:
                path +
                (options ? `?${new URLSearchParams(options.query)}` : ''),
        }),
        show: (id) => ({ url: `${path}/${id}` }),
    });
    const modules = {
        vue: { computed },
        '@inertiajs/vue3': { usePage: () => page },
    };
    for (const [name, path] of [
        [
            'Administration/NotificationController',
            '/administration/notifications',
        ],
        ['Administration/OrderController', '/administration/orders'],
        ['NotificationController', '/notifications'],
        ['OrderController', '/orders'],
        [
            'Administration/InventoryAdjustmentController',
            '/administration/inventory-adjustments',
        ],
        [
            'Administration/DamageReportController',
            '/administration/damage-reports',
        ],
        [
            'Administration/SupplierReturnController',
            '/administration/supplier-returns',
        ],
        [
            'Administration/CustomerReturnController',
            '/administration/customer-returns',
        ],
    ])
        modules[`@/actions/App/Http/Controllers/${name}`] = {
            default: controller(path),
        };
    const source = readFileSync(
        new URL('./useNotificationNavigation.js', import.meta.url),
        'utf8',
    )
        .replace(
            /import\s*\{([^}]+)\}\s*from\s*['"]([^'"]+)['"];?/g,
            (_, names, name) =>
                `const { ${names} } = modules[${JSON.stringify(name)}];`,
        )
        .replace(
            /import\s+(\w+)\s+from\s*['"]([^'"]+)['"];?/g,
            (_, name, module) =>
                `const ${name} = modules[${JSON.stringify(module)}].default;`,
        )
        .replace('export function', 'function');
    return {
        page,
        navigation: new Function(
            'modules',
            `${source}; return useNotificationNavigation();`,
        )(modules),
    };
}

test('operational destinations use the existing case and return-request query parameters', () => {
    const { navigation } = navigationHarness();
    for (const [type, expected] of [
        ['stock_adjustment', '/administration/inventory-adjustments?case=7'],
        ['damage_report', '/administration/damage-reports?case=7'],
        ['supplier_return', '/administration/supplier-returns?case=7'],
        ['customer_return', '/administration/customer-returns?case=7'],
        [
            'customer_return_request',
            '/administration/customer-returns?request_id=7',
        ],
    ])
        assert.equal(
            navigation.destination({ subject: { type, id: 7 } }).url,
            expected,
        );
});

test('customer destinations retain order details and unknown subjects cannot create arbitrary navigation', () => {
    const { page, navigation } = navigationHarness(false);
    assert.equal(
        navigation.destination({
            subject: { type: 'customer_return', id: 7 },
            order: { id: 4 },
        }).url,
        '/orders/4',
    );
    assert.equal(
        navigation.destination({ subject: { type: 'unexpected', id: 7 } }),
        null,
    );
    page.props.auth.can.accessAdministration = true;
    assert.equal(
        navigation.destination({ order: { id: 4 } }).url,
        '/administration/orders/4',
    );
});
