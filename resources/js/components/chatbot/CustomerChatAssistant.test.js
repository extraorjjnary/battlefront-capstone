import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';

const source = readFileSync(
    new URL('./CustomerChatAssistant.vue', import.meta.url),
    'utf8',
);
const { descriptor } = parse(source);
const script = compileScript(descriptor, { id: 'chatbot-composer-test' });
const handler = script.scriptSetupAst.find(
    (node) =>
        node.type === 'FunctionDeclaration' &&
        node.id.name === 'handleComposerKeydown',
);
const handlerSource = descriptor.scriptSetup.content.slice(
    handler.start,
    handler.end,
);

function functionSource(name) {
    const declaration = script.scriptSetupAst.find(
        (node) => node.type === 'FunctionDeclaration' && node.id.name === name,
    );
    return descriptor.scriptSetup.content.slice(
        declaration.start,
        declaration.end,
    );
}

test('New chat clears transcript, token, errors and saved reading position', () => {
    const context = {
        conversationVersion: 1,
        savedScrollTop: 500,
        readerScrolledBack: true,
        messages: { value: [{ content: 'old reply' }] },
        inquiry: {
            message: 'old question',
            context_token: 'old-token',
            clearErrors() {},
        },
        inputError: { value: 'error' },
        requestError: { value: 'error' },
        pendingMessage: { value: 'pending' },
    };
    runInNewContext(
        `${functionSource('startNewChat')}; startNewChat();`,
        context,
    );
    assert.equal(context.messages.value.length, 0);
    assert.equal(context.inquiry.context_token, null);
    assert.equal(context.inquiry.message, '');
    assert.equal(context.requestError.value, '');
    assert.equal(context.pendingMessage.value, '');
    assert.equal(context.conversationVersion, 2);
    assert.equal(context.savedScrollTop, 0);
});

for (const failure of [false, true]) {
    test(`late ${failure ? 'errors' : 'replies'} cannot enter a reset conversation`, async () => {
        let finish;
        const response = new Promise((resolve, reject) => {
            finish = failure ? reject : resolve;
        });
        const context = {
            conversationVersion: 1,
            nextMessageId: 1,
            readerScrolledBack: false,
            messages: { value: [] },
            pendingMessage: { value: '' },
            inputError: { value: '' },
            requestError: { value: '' },
            inquiry: {
                processing: false,
                message: 'monitor',
                clearErrors() {},
                post: () => response,
            },
            scrollToLatest() {},
            showReply() {},
            ChatbotController: { store: { url: () => '/chatbot' } },
        };
        const submit = runInNewContext(
            `(${functionSource('submitMessage')})`,
            context,
        );
        const request = submit();
        context.conversationVersion++;
        context.pendingMessage.value = '';
        finish(
            failure
                ? new Error('old request')
                : { message: 'old reply', context_token: 'old-token' },
        );
        await request;
        assert.equal(context.messages.value.length, 0);
        assert.equal(context.requestError.value, '');
        assert.equal(context.pendingMessage.value, '');
    });
}

for (const [name, event, shouldSubmit] of [
    ['Enter submits instead of inserting a new line', { key: 'Enter' }, true],
    [
        'Shift+Enter submits instead of inserting a new line',
        { key: 'Enter', shiftKey: true },
        true,
    ],
    ['Ctrl+Enter still submits', { key: 'Enter', ctrlKey: true }, true],
    [
        'Enter during text composition does not submit',
        { key: 'Enter', isComposing: true },
        false,
    ],
    ['other keys keep their normal behavior', { key: 'a' }, false],
]) {
    test(name, () => {
        let submissions = 0;
        let prevented = false;
        const handleKeydown = runInNewContext(`(${handlerSource})`, {
            submitMessage: () => {
                submissions++;
            },
        });

        handleKeydown({
            ...event,
            preventDefault: () => {
                prevented = true;
            },
        });

        assert.equal(submissions, shouldSubmit ? 1 : 0);
        assert.equal(prevented, shouldSubmit);
    });
}
