<script setup>
import { useHttp } from '@inertiajs/vue3';
import { LoaderCircle, MessageSquareText, Send, X } from '@lucide/vue';
import { nextTick, ref } from 'vue';
import ChatbotController from '@/actions/App/Http/Controllers/ChatbotController';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';

const inquiry = useHttp({ message: '' });
const messages = ref([]);
const isOpen = ref(false);
const pendingMessage = ref('');
const inputError = ref('');
const requestError = ref('');
const conversation = ref(null);
const composer = ref(null);
const launcher = ref(null);
let nextMessageId = 1;

async function openPanel() {
    isOpen.value = true;
    await nextTick();
    composer.value?.$el?.focus();
    await scrollToLatest();
}

async function closePanel() {
    isOpen.value = false;
    await nextTick();
    launcher.value?.$el?.focus();
}

function handleComposerKeydown(event) {
    if (
        event.key === 'Enter' &&
        (event.ctrlKey || event.metaKey) &&
        !event.isComposing
    ) {
        event.preventDefault();
        void submitMessage();
    }
}

async function scrollToLatest() {
    await nextTick();

    if (conversation.value) {
        conversation.value.scrollTop = conversation.value.scrollHeight;
    }
}

async function submitMessage() {
    if (inquiry.processing) {
        return;
    }

    inputError.value = '';
    requestError.value = '';
    inquiry.clearErrors();

    const message = inquiry.message.trim();

    if (!message) {
        inputError.value = 'Please enter a question.';
        return;
    }

    pendingMessage.value = message;
    void scrollToLatest();

    try {
        const response = await inquiry.post(ChatbotController.store.url());

        if (!response) {
            return;
        }

        messages.value.push(
            { id: nextMessageId++, role: 'customer', content: message },
            {
                id: nextMessageId++,
                role: 'assistant',
                content: response.message,
                source: response.source,
            },
        );
        inquiry.message = '';
    } catch {
        requestError.value = 'Unable to send your question. Please try again.';
    } finally {
        pendingMessage.value = '';
        void scrollToLatest();
    }
}
</script>

<template>
    <Teleport to="body">
        <div
            class="fixed right-3 bottom-[calc(0.75rem+env(safe-area-inset-bottom))] z-50 sm:right-6 sm:bottom-6"
        >
            <Button
                v-if="!isOpen"
                ref="launcher"
                type="button"
                size="lg"
                class="min-h-12 rounded-full px-5 shadow-lg"
                aria-controls="battlefront-chat-panel"
                aria-expanded="false"
                @click="openPanel"
            >
                <MessageSquareText class="size-5" aria-hidden="true" />
                Ask Battlefront
            </Button>

            <section
                v-else
                id="battlefront-chat-panel"
                class="border-border bg-card text-card-foreground flex h-[min(36rem,calc(100dvh-2rem))] w-[calc(100vw-1.5rem)] max-w-96 flex-col overflow-hidden rounded-lg border shadow-2xl"
                aria-label="Battlefront customer support assistant"
                @keydown.esc="closePanel"
            >
                <header
                    class="border-border flex shrink-0 items-center gap-3 border-b px-4 py-3"
                >
                    <span
                        class="bg-secondary text-primary flex size-9 shrink-0 items-center justify-center rounded-md"
                    >
                        <MessageSquareText class="size-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold">
                            Battlefront assistant
                        </p>
                        <p class="text-muted-foreground text-xs">
                            Customer support
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label="Close chat"
                        @click="closePanel"
                    >
                        <X class="size-4" aria-hidden="true" />
                    </Button>
                </header>

                <div
                    ref="conversation"
                    class="min-h-0 flex-1 overflow-y-auto px-4 py-4"
                    :aria-busy="inquiry.processing"
                >
                    <div
                        v-if="messages.length === 0 && !inquiry.processing"
                        class="flex min-h-full flex-col items-center justify-center text-center"
                    >
                        <MessageSquareText
                            class="text-primary size-8"
                            aria-hidden="true"
                        />
                        <h2 class="mt-3 font-semibold">How can we help?</h2>
                        <p
                            class="text-muted-foreground mt-2 max-w-xs text-sm leading-5"
                        >
                            Ask about products, your orders, store information,
                            payment, pickup, or delivery.
                        </p>
                    </div>

                    <ol
                        v-else
                        class="flex flex-col gap-3"
                        role="log"
                        aria-live="polite"
                        aria-relevant="additions"
                        aria-label="Chatbot messages"
                    >
                        <li
                            v-for="message in messages"
                            :key="message.id"
                            class="flex"
                            :class="
                                message.role === 'customer'
                                    ? 'justify-end'
                                    : 'justify-start'
                            "
                        >
                            <article
                                class="max-w-[88%] rounded-md border px-3 py-2"
                                :class="
                                    message.role === 'customer'
                                        ? 'border-primary/30 bg-primary/10'
                                        : message.source === 'fallback'
                                          ? 'border-border bg-secondary/60'
                                          : 'border-border bg-background'
                                "
                            >
                                <p
                                    class="text-muted-foreground text-xs font-semibold"
                                >
                                    {{
                                        message.role === 'customer'
                                            ? 'You'
                                            : 'Battlefront assistant'
                                    }}
                                </p>
                                <p
                                    class="mt-1 text-sm leading-5 wrap-break-word whitespace-pre-wrap"
                                >
                                    {{ message.content }}
                                </p>
                            </article>
                        </li>
                        <li v-if="inquiry.processing" class="flex justify-end">
                            <div
                                class="border-primary/30 bg-primary/10 max-w-[88%] rounded-md border px-3 py-2 text-sm wrap-break-word whitespace-pre-wrap"
                            >
                                {{ pendingMessage }}
                            </div>
                        </li>
                        <li
                            v-if="inquiry.processing"
                            class="flex justify-start"
                        >
                            <div
                                class="border-border bg-background flex items-center gap-2 rounded-md border px-3 py-2 text-sm"
                                role="status"
                            >
                                <LoaderCircle
                                    class="text-primary size-4 animate-spin motion-reduce:animate-none"
                                    aria-hidden="true"
                                />
                                Responding…
                            </div>
                        </li>
                    </ol>
                </div>

                <form
                    class="border-border bg-card shrink-0 border-t p-3"
                    @submit.prevent="submitMessage"
                >
                    <label
                        for="floating-chatbot-message"
                        class="text-sm font-semibold"
                        >Your question</label
                    >
                    <Textarea
                        id="floating-chatbot-message"
                        ref="composer"
                        v-model="inquiry.message"
                        class="mt-2 max-h-28 min-h-18 resize-y"
                        placeholder="Ask about a product, order, or store…"
                        :disabled="inquiry.processing"
                        :aria-invalid="
                            Boolean(inputError || inquiry.errors.message)
                        "
                        aria-describedby="floating-chatbot-help floating-chatbot-error"
                        @input="inputError = ''"
                        @keydown="handleComposerKeydown"
                    />
                    <p
                        v-if="inputError || inquiry.errors.message"
                        id="floating-chatbot-error"
                        class="text-destructive mt-2 text-sm"
                        role="alert"
                    >
                        {{ inputError || inquiry.errors.message }}
                    </p>
                    <p
                        v-if="requestError"
                        class="text-destructive mt-2 text-sm"
                        role="alert"
                    >
                        {{ requestError }}
                    </p>
                    <div class="mt-2 flex items-center justify-between gap-2">
                        <p
                            id="floating-chatbot-help"
                            class="text-muted-foreground text-xs"
                        >
                            Ctrl+Enter to send. Enter for a new line.
                        </p>
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="inquiry.processing"
                        >
                            <LoaderCircle
                                v-if="inquiry.processing"
                                class="size-4 animate-spin motion-reduce:animate-none"
                                aria-hidden="true"
                            />
                            <Send v-else class="size-4" aria-hidden="true" />
                            {{ inquiry.processing ? 'Sending…' : 'Send' }}
                        </Button>
                    </div>
                </form>
            </section>
        </div>
    </Teleport>
</template>
