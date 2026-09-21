<script setup>
import { Head } from '@inertiajs/vue3';
import { MessageSquarePlus } from '@lucide/vue';
import ChatbotKnowledgeController from '@/actions/App/Http/Controllers/Administration/ChatbotKnowledgeController';
import ChatbotKnowledgeForm from '@/components/ChatbotKnowledgeForm.vue';

defineProps({
    categories: { type: Array, required: true },
});

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Chatbot knowledge',
                href: ChatbotKnowledgeController.index(),
            },
            {
                title: 'Add knowledge',
                href: ChatbotKnowledgeController.create(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Add chatbot knowledge" />

    <main
        class="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-8 p-6 lg:p-10"
    >
        <section
            class="border-border bg-card relative overflow-hidden border p-6"
        >
            <div class="bg-primary absolute inset-y-0 left-0 w-1"></div>
            <div class="flex items-start gap-4">
                <span
                    class="bg-secondary text-primary flex size-11 shrink-0 items-center justify-center rounded-md"
                >
                    <MessageSquarePlus class="size-5" />
                </span>
                <div>
                    <p
                        class="text-primary text-xs font-semibold tracking-widest uppercase"
                    >
                        Customer support content
                    </p>
                    <h1 class="mt-2 text-2xl font-bold tracking-tight">
                        Add chatbot knowledge
                    </h1>
                    <p class="text-muted-foreground mt-2 text-sm leading-6">
                        Create an approved question and response record for
                        future chatbot use.
                    </p>
                </div>
            </div>
        </section>

        <section class="border-border bg-card border p-6 sm:p-8">
            <ChatbotKnowledgeForm
                :categories="categories"
                :form="ChatbotKnowledgeController.store.form()"
                submit-label="Create knowledge"
            />
        </section>
    </main>
</template>
