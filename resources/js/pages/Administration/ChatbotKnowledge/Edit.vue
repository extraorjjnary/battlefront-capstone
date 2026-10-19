<script setup>
import { Head } from '@inertiajs/vue3';
import { MessageSquareMore } from '@lucide/vue';
import ChatbotKnowledgeController from '@/actions/App/Http/Controllers/Administration/ChatbotKnowledgeController';
import ChatbotKnowledgeForm from '@/components/ChatbotKnowledgeForm.vue';
import { Badge } from '@/components/ui/badge';

defineProps({
    knowledge: { type: Object, required: true },
});

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Chatbot FAQs',
                href: ChatbotKnowledgeController.index(),
            },
            {
                title: 'Edit FAQ',
                href: ChatbotKnowledgeController.index(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Edit chatbot FAQ" />

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
                    <MessageSquareMore class="size-5" />
                </span>
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <p
                            class="text-primary-ink text-xs font-semibold tracking-widest uppercase"
                        >
                            Customer support content
                        </p>
                        <Badge
                            :variant="
                                knowledge.is_active ? 'secondary' : 'outline'
                            "
                        >
                            {{ knowledge.is_active ? 'Active' : 'Inactive' }}
                        </Badge>
                    </div>
                    <h1 class="mt-2 text-2xl font-bold tracking-tight">
                        Edit chatbot FAQ
                    </h1>
                    <p class="text-muted-foreground mt-2 text-sm leading-6">
                        Update approved FAQ guidance while preserving its activation state.
                    </p>
                </div>
            </div>
        </section>

        <section class="surface-panel border-border bg-card border p-6 sm:p-8">
            <ChatbotKnowledgeForm
                :knowledge="knowledge"
                :form="ChatbotKnowledgeController.update.form(knowledge.id)"
                submit-label="Save changes"
            />
        </section>
    </main>
</template>
