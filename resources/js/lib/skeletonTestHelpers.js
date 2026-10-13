import { readFileSync } from 'node:fs';
import { compileScript, parse } from '@vue/compiler-sfc';
import * as Vue from 'vue';
import { cn } from './utils.js';

export function compileSkeletonTestComponent(
    source,
    modules,
    transform = (code) => code,
) {
    const { descriptor } = parse(source);
    const script = compileScript(descriptor, {
        id: 'skeleton-test',
        inlineTemplate: true,
    });
    const code = transform(script.content)
        .replace(
            /import\s*\{([^}]+)\}\s*from\s*['"]([^'"]+)['"];?/g,
            (_, names, moduleName) =>
                `const { ${names.replace(/\s+as\s+/g, ': ')} } = modules[${JSON.stringify(moduleName)}];`,
        )
        .replace(
            /import\s+(\w+)\s+from\s*['"]([^'"]+)['"];?/g,
            (_, name, moduleName) =>
                `const ${name} = modules[${JSON.stringify(moduleName)}].default;`,
        )
        .replace('export default', 'return');

    return new Function('modules', code)(modules);
}

const skeletonComponents = new Map();

export function loadSkeletonComponent(name) {
    if (!skeletonComponents.has(name)) {
        const modules = {
            vue: Vue,
            '@/lib/utils': { cn },
        };
        if (name !== 'ui/skeleton/Skeleton.vue') {
            modules['@/components/ui/skeleton'] = {
                Skeleton: loadSkeletonComponent('ui/skeleton/Skeleton.vue'),
            };
        }
        skeletonComponents.set(
            name,
            compileSkeletonTestComponent(
                readFileSync(
                    new URL(`../components/${name}`, import.meta.url),
                    'utf8',
                ),
                modules,
            ),
        );
    }

    return skeletonComponents.get(name);
}
