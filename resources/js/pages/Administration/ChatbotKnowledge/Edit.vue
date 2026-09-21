<script setup>
import { Head } from '@inertiajs/vue3';
import { MessageSquareMore } from '@lucide/vue';
import ChatbotKnowledgeController from '@/actions/App/Http/Controllers/Administration/ChatbotKnowledgeController';
import ChatbotKnowledgeForm from '@/components/ChatbotKnowledgeForm.vue';
import { Badge } from '@/components/ui/badge';

defineProps({
    knowledge: { type: Object, required: true },
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
                title: 'Edit knowledge',
                href: ChatbotKnowledgeController.index(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Edit chatbot knowledge" />

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
                    <MessageSquareMore class="size-5" />
                </span>
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <p
                            class="text-primary text-xs font-semibold tracking-widest uppercase"
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
                        Edit chatbot knowledge
                    </h1>
                    <p class="text-muted-foreground mt-2 text-sm leading-6">
                        Update the approved content without changing its
                        lifecycle state.
                    </p>
                </div>
            </div>
        </section>

        <section class="border-border bg-card border p-6 sm:p-8">
            <ChatbotKnowledgeForm
                :knowledge="knowledge"
                :categories="categories"
                :form="ChatbotKnowledgeController.update.form(knowledge.id)"
                submit-label="Save changes"
            />
        </section>
    </main>
</template>
