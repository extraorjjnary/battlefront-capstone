import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import * as Vue from 'vue';

function loadTypescript(path, modules = {}) {
    const source = ts.transpileModule(
        readFileSync(new URL(path, import.meta.url), 'utf8'),
        { compilerOptions: { module: ts.ModuleKind.CommonJS } },
    ).outputText;
    const module = { exports: {} };
    new Function('require', 'exports', 'module', source)(
        (name) => modules[name],
        module.exports,
        module,
    );
    return module.exports;
}

const wayfinder = loadTypescript('../../wayfinder/index.ts');
const routes = loadTypescript(
    '../../actions/App/Http/Controllers/ProductCatalogController.ts',
    { './../../../../wayfinder': wayfinder },
);
const modules = {
    vue: Vue,
    '@inertiajs/vue3': {
        Link: {
            props: ['href', 'tabindex', 'method', 'as'],
            setup:
                (props, { slots }) =>
                () =>
                    Vue.h('a', { href: props.href }, slots.default?.()),
        },
    },
    '@/actions/App/Http/Controllers/ProductCatalogController': routes,
};

function loadComponent(path) {
    const { descriptor } = parse(
        readFileSync(new URL(path, import.meta.url), 'utf8'),
    );
    const script = compileScript(descriptor, {
        id: 'chatbot-message-test',
        inlineTemplate: true,
    });
    const source = script.content
        .replace(
            /import\s*\{([^}]+)\}\s*from\s*['"]([^'"]+)['"];?/g,
            (_, names, name) =>
                `const { ${names.replace(/\s+as\s+/g, ': ')} } = modules[${JSON.stringify(name)}];`,
        )
        .replace(
            /import\s+(\w+)\s+from\s*['"]([^'"]+)['"];?/g,
            (_, name, path) =>
                `const ${name} = modules[${JSON.stringify(path)}];`,
        )
        .replace('export default', 'return');
    return new Function('modules', source)(modules);
}

modules['@/components/TextLink.vue'] = loadComponent('../TextLink.vue');
const message = loadComponent('./ChatbotMessage.vue');
const render = async (content, products = []) =>
    (await renderToString(Vue.h(message, { content, products }))).replace(
        /<!--[\s\S]*?-->/g,
        '',
    );

test('one product name uses its generated details route and preserves displayed spelling', async () => {
    const html = await render('Ang atlas monitor x240 ay PHP 4,200.00.', [
        { id: 17, name: 'Atlas Monitor X240' },
    ]);

    assert.match(html, /href="\/products\/17"[^>]*>atlas monitor x240<\/a>/);
    assert.equal((html.match(/<a\b/g) ?? []).length, 1);
    assert.equal(
        html.replace(/<[^>]+>/g, ''),
        'Ang atlas monitor x240 ay PHP 4,200.00.',
    );
});

test('each product in a list has its own link while paragraphs and line breaks remain', async () => {
    const content =
        'Available products:\n\n1. Atlas Monitor X240\n2. Helios Mouse Z10';
    const html = await render(content, [
        { id: 17, name: 'Atlas Monitor X240' },
        { id: 29, name: 'Helios Mouse Z10' },
    ]);

    assert.match(html, /href="\/products\/17"[^>]*>Atlas Monitor X240<\/a>/);
    assert.match(html, /href="\/products\/29"[^>]*>Helios Mouse Z10<\/a>/);
    assert.equal((html.match(/<p\b/g) ?? []).length, 2);
    assert.match(html, /whitespace-pre-wrap/);
    assert.match(html, /\n2\. /);
});

test('HTML and Markdown remain escaped text and cannot supply link destinations', async () => {
    const name = 'Atlas <img src=x onerror=alert(1)> & "Monitor"';
    const content = `${name}\n<script>alert(1)</script> [open](javascript:alert(1))`;
    const html = await render(content, [
        { id: 17, name, url: 'javascript:alert(1)' },
    ]);

    assert.match(html, /<a href="\/products\/17"/);
    assert.match(html, /&lt;img/);
    assert.match(html, /&lt;script&gt;/);
    assert.match(html, /&amp;/);
    assert.doesNotMatch(html, /<script\b|<img\b|href="javascript:/i);
    assert.equal((html.match(/<a\b/g) ?? []).length, 1);
});

test('overlapping names use the full product and repeated mentions link independently', async () => {
    const html = await render(
        'Atlas Monitor Pro, Atlas Monitor, Atlas Monitor Pro.',
        [
            { id: 17, name: 'Atlas Monitor' },
            { id: 29, name: 'Atlas Monitor Pro' },
        ],
    );

    assert.equal((html.match(/href="\/products\/29"/g) ?? []).length, 2);
    assert.equal((html.match(/href="\/products\/17"/g) ?? []).length, 1);
});

test('names with punctuation are matched literally and do not link inside another word', async () => {
    const html = await render(
        'Atlas C++ (24") / Atlas C++ (24"). AtlasOne Atlas.',
        [
            { id: 17, name: 'Atlas C++ (24")' },
            { id: 29, name: 'Atlas' },
        ],
    );

    assert.equal((html.match(/href="\/products\/17"/g) ?? []).length, 2);
    assert.equal((html.match(/href="\/products\/29"/g) ?? []).length, 1);
    assert.match(html, /AtlasOne/);
});

test('legacy messages and unknown product mentions remain text', async () => {
    for (const products of [[], [{ id: 17, name: 'Atlas Monitor X240' }]]) {
        const html = await render(
            'Helios Mouse Z10\n\nPlease ask about its price.',
            products,
        );
        assert.doesNotMatch(html, /<a\b/);
        assert.match(html, /Helios Mouse Z10/);
        assert.equal((html.match(/<p\b/g) ?? []).length, 2);
    }
});

test('invalid product identifiers cannot become route parameters', async () => {
    const html = await render('Atlas Monitor', [
        { id: 'javascript:alert(1)', name: 'Atlas Monitor' },
        { id: -1, name: 'Atlas Monitor' },
        { id: 0, name: 'Atlas Monitor' },
        null,
    ]);

    assert.doesNotMatch(html, /<a\b/);
    assert.match(html, /Atlas Monitor/);
});
