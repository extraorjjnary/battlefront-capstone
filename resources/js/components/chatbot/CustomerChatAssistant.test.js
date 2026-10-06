import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';

const source = readFileSync(new URL('./CustomerChatAssistant.vue', import.meta.url), 'utf8');
const { descriptor } = parse(source);
const script = compileScript(descriptor, { id: 'chatbot-composer-test' });
const handler = script.scriptSetupAst.find(
    (node) => node.type === 'FunctionDeclaration' && node.id.name === 'handleComposerKeydown',
);
const handlerSource = descriptor.scriptSetup.content.slice(handler.start, handler.end);

for (const [name, event, shouldSubmit] of [
    ['Enter submits instead of inserting a new line', { key: 'Enter' }, true],
    ['Shift+Enter submits instead of inserting a new line', { key: 'Enter', shiftKey: true }, true],
    ['Ctrl+Enter still submits', { key: 'Enter', ctrlKey: true }, true],
    ['Enter during text composition does not submit', { key: 'Enter', isComposing: true }, false],
    ['other keys keep their normal behavior', { key: 'a' }, false],
]) {
    test(name, () => {
        let submissions = 0;
        let prevented = false;
        const handleKeydown = runInNewContext(`(${handlerSource})`, {
            submitMessage: () => { submissions++; },
        });

        handleKeydown({
            ...event,
            preventDefault: () => { prevented = true; },
        });

        assert.equal(submissions, shouldSubmit ? 1 : 0);
        assert.equal(prevented, shouldSubmit);
    });
}
