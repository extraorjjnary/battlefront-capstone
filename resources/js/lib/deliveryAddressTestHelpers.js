import { readFileSync } from 'node:fs';
import { compileScript, parse } from '@vue/compiler-sfc';
import * as Vue from 'vue';
import { formatCurrency } from './currency.js';

export const deliveryLocations = JSON.parse(
    readFileSync(new URL('../../data/psgc-delivery-2026-03-31.v1.json', import.meta.url), 'utf8'),
).destinations;

export function emptyDeliveryAddress() {
    return {
        delivery_destination: '',
        delivery_barangay_code: '',
        delivery_address_details: '',
    };
}

export function loadAddressComponent(path, inlineTemplate = false, options = {}) {
    const { errors = {}, user = { name: 'Customer', email: 'customer@example.com' } } = options;
    const primitive = {
        render() {
            const node = Vue.h('div', this.$attrs, this.$slots.default?.({ errors, processing: false }));
            if (this.$attrs.action) options.captureForm?.(node);
            return node;
        },
    };
    const textarea = {
        props: ['modelValue'],
        render() {
            return Vue.h('textarea', { ...this.$attrs, value: this.modelValue });
        },
    };
    const route = () => ({ url: '/orders', method: 'post' });
    route.form = () => ({ action: '/orders', method: 'post' });
    route.url = (options = {}) => '/delivery-locations?' + new URLSearchParams(options.query ?? {});
    const generic = new Proxy({}, {
        get: (_, key) => ['store', 'index', 'edit', 'show'].includes(key) ? route : primitive,
    });
    const modules = new Proxy({
        vue: Vue,
        '@inertiajs/vue3': {
            Head: primitive, Link: primitive, Form: primitive,
            usePage: () => ({ props: { auth: { user } } }),
        },
        '@/lib/currency': { formatCurrency },
        '@/components/ui/textarea': { Textarea: textarea },
        '@/components/ui/button': {
            Button: { render() { return Vue.h('button', this.$attrs, this.$slots.default?.()); } },
        },
        '@/components/InputError.vue': {
            default: {
                props: ['message'],
                render() { return Vue.h('p', this.message); },
            },
        },
        '@/actions/App/Http/Controllers/Settings/ProfileController': {
            default: { update: { form: () => ({ action: '/settings/profile', method: 'patch' }) } },
        },
    }, {
        get: (target, key) => {
            if (key === '@/components/DeliveryAddressFields.vue') {
                return { default: loadAddressComponent('../components/DeliveryAddressFields.vue', true, options) };
            }
            return target[key] ?? generic;
        },
    });
    const { descriptor } = parse(readFileSync(new URL(path, import.meta.url), 'utf8'));
    const script = compileScript(descriptor, { id: 'delivery-address-test', inlineTemplate });
    const code = script.content
        .replace(
            /import\s*\{([^}]+)\}\s*from\s*['"]([^'"]+)['"];?/g,
            (_, names, moduleName) => 'const { ' + names.replace(/\s+as\s+/g, ': ') + ' } = modules[' + JSON.stringify(moduleName) + '];',
        )
        .replace(
            /import\s+(\w+)\s+from\s*['"]([^'"]+)['"];?/g,
            (_, name, moduleName) => 'const ' + name + ' = modules[' + JSON.stringify(moduleName) + '].default;',
        )
        .replace('export default', 'return');

    return new Function('modules', code)(modules);
}

export function findAddressNode(node, predicate) {
    if (!node || typeof node !== 'object') return;
    if (predicate(node)) return node;
    let children = node.children;
    if (children && !Array.isArray(children) && typeof children === 'object') {
        children = Object.values(children)
            .filter((slot) => typeof slot === 'function')
            .flatMap((slot) => slot({ errors: {}, processing: false }));
    }
    if (!Array.isArray(children)) return;
    return children.map((child) => findAddressNode(child, predicate)).find(Boolean);
}
