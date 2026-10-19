<script setup>
import { Head } from '@inertiajs/vue3';
import { MessageSquarePlus } from '@lucide/vue';
import ChatbotKnowledgeController from '@/actions/App/Http/Controllers/Administration/ChatbotKnowledgeController';
import ChatbotKnowledgeForm from '@/components/ChatbotKnowledgeForm.vue';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Chatbot FAQs',
                href: ChatbotKnowledgeController.index(),
            },
            {
                title: 'Add FAQ',
                href: ChatbotKnowledgeController.create(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Add chatbot FAQ" />

    <main
        class="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-8 p-6 lg:p-10"
    >
        <section
            class="surface-panel border-border bg-card relative overflow-hidden border p-6"
        >
            <div class="bg-primary absolute inset-y-0 left-0 w-1"></div>
            <div class="flex items-start gap-4">
                <span
                    class="bg-secondary text-primary-ink flex size-11 shrink-0 items-center justify-center rounded-md"
                >
                    <MessageSquarePlus class="size-5" />
                </span>
                <div>
                    <p
                        class="text-primary-ink text-xs font-semibold tracking-widest uppercase"
                    >
                        Customer support content
                    </p>
                    <h1 class="mt-2 text-2xl font-bold tracking-tight">
                        Add chatbot FAQ
                    </h1>
                    <p class="text-muted-foreground mt-2 text-sm leading-6">
                        Create approved Battlefront support guidance. Check the question before saving to confirm where it belongs.
                    </p>
                </div>
            </div>
        </section>

        <section class="surface-panel border-border bg-card border p-6 sm:p-8">
            <ChatbotKnowledgeForm
                :form="ChatbotKnowledgeController.store.form()"
                submit-label="Create FAQ"
            />
        </section>
    </main>
</template>
