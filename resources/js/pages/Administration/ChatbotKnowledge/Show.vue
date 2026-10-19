<script setup>
import { Form, Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, MessageSquareText, Pencil, RotateCcw } from '@lucide/vue';
import ChatbotKnowledgeActivationController from '@/actions/App/Http/Controllers/Administration/ChatbotKnowledgeActivationController';
import ChatbotKnowledgeController from '@/actions/App/Http/Controllers/Administration/ChatbotKnowledgeController';
import InputError from '@/components/InputError.vue';
import DeactivationDialog from '@/components/DeactivationDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

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
                title: 'FAQ record',
                href: ChatbotKnowledgeController.index(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Chatbot FAQ record" />

    <main
        class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-8 p-6 lg:p-10"
    >
        <section class="surface-panel border-border bg-card relative overflow-hidden border">
            <div class="bg-primary absolute inset-y-0 left-0 w-1"></div>
            <div
                class="flex flex-col gap-5 p-6 sm:flex-row sm:items-start sm:justify-between"
            >
                <div class="flex min-w-0 items-start gap-4">
                    <span
                        class="bg-secondary text-primary-ink flex size-11 shrink-0 items-center justify-center rounded-md"
                    >
                        <MessageSquareText class="size-5" />
                    </span>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p
                                class="text-primary-ink text-xs font-semibold tracking-widest uppercase"
                            >
                                Chatbot FAQ record
                            </p>
                            <Badge
                                :variant="
                                    knowledge.is_active
                                        ? 'secondary'
                                        : 'outline'
                                "
                            >
                                {{
                                    knowledge.is_active ? 'Active' : 'Inactive'
                                }}
                            </Badge>
                        </div>
                        <h1
                            class="mt-2 text-2xl font-bold tracking-tight sm:text-3xl"
                        >
                            {{ knowledge.question_pattern }}
                        </h1>
                        <p v-if="!knowledge.eligible_for_faq" class="text-muted-foreground mt-2 text-sm">Needs review: {{ knowledge.eligibility_reason }}</p>
                        <p class="text-muted-foreground mt-2 text-sm">
                            {{ knowledge.category_label }}
                        </p>
                    </div>
                </div>

                <Button variant="outline" size="sm" as-child>
                    <Link :href="ChatbotKnowledgeController.index()">
                        <ArrowLeft />
                        Back to FAQs
                    </Link>
                </Button>
            </div>
        </section>

        <section aria-labelledby="response-heading">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-muted-foreground text-sm">
                        Approved content
                    </p>
                    <h2 id="response-heading" class="text-xl font-semibold">
                        Approved answer
                    </h2>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" size="sm" as-child>
                        <Link
                            :href="
                                ChatbotKnowledgeController.edit(knowledge.id)
                            "
                        >
                            <Pencil />
                            Edit
                        </Link>
                    </Button>
                    <DeactivationDialog
                        v-if="knowledge.is_active"
                        :name="knowledge.question_pattern"
                        :form="
                            ChatbotKnowledgeActivationController.form(
                                knowledge.id,
                            )
                        "
                        description="This record will remain available to administrators and can be reactivated later."
                    />
                    <Form
                        v-else
                        v-bind="
                            ChatbotKnowledgeActivationController.form(
                                knowledge.id,
                            )
                        "
                        v-slot="{ errors, processing }"
                    >
                        <input type="hidden" name="is_active" value="1" />
                        <InputError :message="errors.is_active" />
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="processing || !knowledge.eligible_for_faq"
                        >
                            <Spinner v-if="processing" />
                            <RotateCcw v-else />
                            Reactivate
                        </Button>
                    </Form>
                </div>
            </div>

            <div class="surface-panel border-border bg-card border p-6">
                <p class="text-sm leading-7 whitespace-pre-wrap">
                    {{ knowledge.response_template }}
                </p>
            </div>
        </section>
    </main>
</template>
