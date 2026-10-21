import { readFileSync } from 'node:fs';
import { compileScript, parse } from '@vue/compiler-sfc';
import * as Vue from 'vue';

const { descriptor } = parse(
    readFileSync(new URL('./PageHeader.vue', import.meta.url), 'utf8'),
);
const script = compileScript(descriptor, {
    id: 'page-header-test',
    inlineTemplate: true,
});
const code = script.content
    .replace(
        /import\s*\{([^}]+)\}\s*from\s*['"]vue['"];?/g,
        (_, names) => `const { ${names.replace(/\s+as\s+/g, ': ')} } = Vue;`,
    )
    .replace('export default', 'return');

export const pageHeaderModule = {
    default: new Function('Vue', code)(Vue),
};
