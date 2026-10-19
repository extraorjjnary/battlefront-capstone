import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import * as icons from '@lucide/vue';
import { reactiveOmit } from '@vueuse/core';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import { cva } from 'class-variance-authority';
import { Primitive } from 'reka-ui';
import * as Vue from 'vue';
import { cn, toUrl } from '../lib/utils.js';

function resolveImports(code) {
    return code
        .replace(
            /import\s*\{([^}]+)\}\s*from\s*['"]([^'"]+)['"];?/g,
            (_, names, name) =>
                `const { ${names.replace(/\s+as\s+/g, ': ')} } = modules[${JSON.stringify(name)}];`,
        )
        .replace(
            /import\s+(\w+)\s+from\s*['"]([^'"]+)['"];?/g,
            (_, name, moduleName) =>
                `const ${name} = modules[${JSON.stringify(moduleName)}].default;`,
        );
}

function navigationHarness({
    quantity = 0,
    administrator = false,
    catalogRole = false,
    manageCatalog = false,
    mode = 'expanded',
} = {}) {
    const page = Vue.reactive({
        props: {
            cartQuantity: quantity,
            auth: {
                can: {
                    accessAdministration: administrator,
                    browseProductCatalog: catalogRole,
                    manageProducts: manageCatalog,
                    viewOrders: administrator,
                    useCustomerCart: !administrator,
                },
            },
        },
    });
    const primitive = {
        render: function () {
            return Vue.h('div', this.$slots.default?.());
        },
    };
    const route = (url) => () => ({ url, method: 'get' });
    const generic = { default: { index: route('/administration') } };
    const modules = new Proxy(
        {
            vue: Vue,
            'reka-ui': { Primitive },
            'class-variance-authority': { cva },
            '@vueuse/core': { reactiveOmit },
            '@lucide/vue': icons,
            '@inertiajs/vue3': {
                usePage: () => page,
                Link: {
                    props: ['href'],
                    render() {
                        return Vue.h(
                            'a',
                            { ...this.$attrs, href: toUrl(this.href) },
                            this.$slots.default?.(),
                        );
                    },
                },
            },
            '@/lib/utils': { cn },
            '@/routes': { dashboard: route('/dashboard') },
            '@/routes/branches': { index: route('/branches') },
            '@/routes/cart': { index: route('/cart') },
            '@/routes/orders': { index: route('/orders') },
            '@/routes/products': { index: route('/products') },
            '@/routes/profile': { edit: route('/settings/profile') },
            '@/components/ui/tooltip': {
                Tooltip: primitive,
                TooltipContent: primitive,
                TooltipTrigger: primitive,
            },
            './utils': {
                useSidebar: () => ({
                    state: Vue.ref(
                        mode === 'collapsed' ? 'collapsed' : 'expanded',
                    ),
                    isMobile: Vue.ref(mode === 'mobile'),
                }),
            },
            '@/composables/useCurrentUrl': {
                useCurrentUrl: () => ({
                    isCurrentUrl: () => false,
                    isCurrentOrParentUrl: () => false,
                }),
            },
        },
        { get: (target, key) => target[key] ?? generic },
    );

    function component(path) {
        const { descriptor } = parse(
            readFileSync(new URL(path, import.meta.url), 'utf8'),
        );
        const script = compileScript(descriptor, {
            id: 'nav-main-test',
            inlineTemplate: true,
        });
        return new Function(
            'modules',
            resolveImports(script.content).replace('export default', 'return'),
        )(modules);
    }

    const variants = readFileSync(
        new URL('./ui/sidebar/index.js', import.meta.url),
        'utf8',
    )
        .replace(/^export \{.*$/gm, '')
        .replace('export const', 'const');
    modules['.'] = new Function(
        'modules',
        resolveImports(variants) + '\nreturn { sidebarMenuButtonVariants };',
    )(modules);
    modules['./SidebarMenuButtonChild.vue'] = {
        default: component('./ui/sidebar/SidebarMenuButtonChild.vue'),
    };
    modules['@/components/ui/sidebar'] = Object.fromEntries(
        [
            'SidebarGroup',
            'SidebarGroupLabel',
            'SidebarMenu',
            'SidebarMenuButton',
            'SidebarMenuItem',
        ].map((name) => [name, component(`./ui/sidebar/${name}.vue`)]),
    );
    const navigationSource = readFileSync(
        new URL('../composables/useAppNavigation.js', import.meta.url),
        'utf8',
    );
    const { useAppNavigation } = new Function(
        'modules',
        resolveImports(navigationSource).replace(
            'export function',
            'function',
        ) + '\nreturn { useAppNavigation };',
    )(modules);
    const navigation = useAppNavigation();
    const nav = component('./NavMain.vue');
    return {
        page,
        navigation,
        render: () =>
            renderToString(
                Vue.h(
                    'div',
                    {
                        class: 'group',
                        'data-collapsible': mode === 'collapsed' ? 'icon' : '',
                        'data-mobile': mode === 'mobile' ? 'true' : undefined,
                    },
                    [
                        Vue.h(nav, {
                            items: navigation.mainNavItems.value,
                            label: navigation.sectionLabel.value,
                        }),
                    ],
                ),
            ),
    };
}

function cartLink(html) {
    return html.match(/<a\b[^>]*href="\/cart"[^>]*>.*?<\/a>/s)?.[0];
}

for (const mode of ['expanded', 'collapsed', 'mobile']) {
    for (const [quantity, label] of [
        [0, null],
        [1, '1'],
        [12, '12'],
        [99, '99'],
        [100, '99+'],
    ]) {
        test(`${mode} sidebar shows ${label ?? 'no badge'} for ${quantity} cart units`, async () => {
            const html = await navigationHarness({ quantity, mode }).render();
            const cart = cartLink(html);
            assert.match(cart, /lucide-shopping-cart/);
            assert.match(
                cart,
                new RegExp(
                    `aria-label="Cart, ${quantity} ${quantity === 1 ? 'unit' : 'units'} in cart"`,
                ),
            );
            if (label === null) {
                assert.doesNotMatch(cart, /data-slot="cart-quantity-badge"/);
            } else {
                const badge = cart.match(
                    /<span\b[^>]*data-slot="cart-quantity-badge"[^>]*>.*?<\/span>/s,
                )?.[0];
                assert.match(
                    badge,
                    new RegExp(`>\\s*${label.replace('+', '\\+')}\\s*</span>`),
                );
                assert.match(badge, /aria-hidden="true"/);
                assert.match(badge, /bg-destructive-action /);
                assert.doesNotMatch(
                    badge.match(/class="([^"]*)"/)[1],
                    /hidden|truncate|overflow-hidden|group-data/,
                );
                assert.match(cart, /class="relative size-4 shrink-0"/);
                assert.match(badge, /absolute -top-1\.5 -right-1\.5/);
            }
            const orders = html.match(
                /<a\b[^>]*href="\/orders"[^>]*>.*?<\/a>/s,
            )?.[0];
            assert.match(orders, /<svg\b[^>]*class="[^"]*lucide-receipt-text/);
            assert.doesNotMatch(orders, /cart-quantity-badge|relative size-4/);
        });
    }
}

test('shared count updates recompute the existing navigation and clear the badge', async () => {
    const harness = navigationHarness({ quantity: 2 });
    const cart = () =>
        harness.navigation.mainNavItems.value.find(
            (item) => item.title === 'Cart',
        );
    assert.equal(cart().badgeCount, 2);
    harness.page.props.cartQuantity = 103;
    assert.equal(cart().badgeCount, 103);
    assert.match(cartLink(await harness.render()), />99\+<\/span>/);
    harness.page.props.cartQuantity = 0;
    assert.doesNotMatch(
        cartLink(await harness.render()),
        /cart-quantity-badge/,
    );
});

test('administrator navigation keeps Orders and never receives a Cart badge', async () => {
    const harness = navigationHarness({ quantity: 103, administrator: true });
    const html = await harness.render();
    assert.equal(harness.navigation.sectionLabel.value, 'Administration');
    assert.equal(
        harness.navigation.mainNavItems.value.find(
            (item) => item.title === 'Orders',
        ).icon,
        icons.ReceiptText,
    );
    assert.equal(
        harness.navigation.mainNavItems.value.some(
            (item) => item.badgeCount !== undefined,
        ),
        false,
    );
    assert.doesNotMatch(html, /cart-quantity-badge|lucide-shopping-cart/);
});

test('business roles can open the storefront catalog from administration navigation', async () => {
    const harness = navigationHarness({
        administrator: true,
        catalogRole: true,
    });
    const catalog = harness.navigation.mainNavItems.value.find(
        (item) => item.title === 'Products',
    );

    assert.equal(toUrl(catalog.href), '/products');
    assert.equal(
        harness.navigation.mainNavItems.value.some(
            (item) => item.title === 'Manage products',
        ),
        false,
    );
    assert.doesNotMatch(await harness.render(), /lucide-shopping-cart/);

    const manager = navigationHarness({
        administrator: true,
        catalogRole: true,
        manageCatalog: true,
    });
    assert.equal(
        toUrl(
            manager.navigation.mainNavItems.value.find(
                (item) => item.title === 'Manage products',
            ).href,
        ),
        '/administration',
    );

    const it = navigationHarness({ administrator: true });
    assert.equal(
        it.navigation.mainNavItems.value.some(
            (item) => item.title === 'Products',
        ),
        false,
    );
});
