<script setup>
import { Form, Head, Link } from '@inertiajs/vue3';
import { Eye, MessageSquareText, Pencil, Plus, RotateCcw } from '@lucide/vue';
import ChatbotKnowledgeActivationController from '@/actions/App/Http/Controllers/Administration/ChatbotKnowledgeActivationController';
import ChatbotKnowledgeController from '@/actions/App/Http/Controllers/Administration/ChatbotKnowledgeController';
import CatalogPagination from '@/components/CatalogPagination.vue';
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
                title: 'Chatbot knowledge',
                href: ChatbotKnowledgeController.index(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Chatbot knowledge" />

    <main
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-8 p-6 lg:p-10"
    >
        <section class="border-border bg-card relative overflow-hidden border">
            <div class="bg-primary absolute inset-y-0 left-0 w-1"></div>
            <div
                class="flex flex-col gap-5 p-6 sm:flex-row sm:items-start sm:justify-between"
            >
                <div class="flex items-start gap-4">
                    <span
                        class="bg-secondary text-primary flex size-11 shrink-0 items-center justify-center rounded-md"
                    >
                        <MessageSquareText class="size-5" />
                    </span>
                    <div>
                        <p
                            class="text-primary text-xs font-semibold tracking-widest uppercase"
                        >
                            Customer support content
                        </p>
                        <h1
                            class="mt-2 text-2xl font-bold tracking-tight sm:text-3xl"
                        >
                            Chatbot knowledge management
                        </h1>
                        <p
                            class="text-muted-foreground mt-2 max-w-2xl text-sm leading-6"
                        >
                            Maintain approved question patterns and response
                            templates without configuring chatbot retrieval or
                            providers.
                        </p>
                    </div>
                </div>
                <Button as-child class="shrink-0">
                    <Link :href="ChatbotKnowledgeController.create()">
                        <Plus />
                        Add knowledge
                    </Link>
                </Button>
            </div>
        </section>

        <section aria-labelledby="knowledge-list-heading">
            <div class="mb-4">
                <p class="text-muted-foreground text-sm">
                    {{ knowledge.total }} records
                </p>
                <h2 id="knowledge-list-heading" class="text-xl font-semibold">
                    Knowledge records
                </h2>
            </div>

            <div
                v-if="knowledge.data.length === 0"
                class="border-border bg-card flex min-h-52 items-center justify-center border p-6 text-center"
            >
                <div>
                    <MessageSquareText
                        class="text-muted-foreground mx-auto size-9"
                    />
                    <p class="mt-3 font-medium">
                        No chatbot knowledge available
                    </p>
                    <p class="text-muted-foreground mt-1 text-sm">
                        Add an approved response record to begin managing
                        knowledge.
                    </p>
                </div>
            </div>

            <div
                v-else
                class="border-border bg-card divide-border divide-y border"
            >
                <article
                    v-for="item in knowledge.data"
                    :key="item.id"
                    class="grid gap-5 p-5 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center"
                >
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge variant="outline">{{
                                item.category_label
                            }}</Badge>
                            <Badge
                                :variant="
                                    item.is_active ? 'secondary' : 'outline'
                                "
                            >
                                {{ item.is_active ? 'Active' : 'Inactive' }}
                            </Badge>
                            <span class="text-muted-foreground text-xs"
                                >Priority {{ item.priority }}</span
                            >
                        </div>
                        <h3 class="mt-3 font-semibold">
                            {{ item.question_pattern }}
                        </h3>
                        <p
                            class="text-muted-foreground mt-2 line-clamp-2 max-w-3xl text-sm leading-6 whitespace-pre-line"
                        >
                            {{ item.response_template }}
                        </p>
                    </div>

                    <div
                        class="flex flex-wrap items-center gap-2 lg:justify-end"
                    >
                        <Button variant="outline" size="sm" as-child>
                            <Link
                                :href="ChatbotKnowledgeController.show(item.id)"
                            >
                                <Eye />
                                View
                            </Link>
                        </Button>
                        <Button variant="outline" size="sm" as-child>
                            <Link
                                :href="ChatbotKnowledgeController.edit(item.id)"
                            >
                                <Pencil />
                                Edit
                            </Link>
                        </Button>
                        <DeactivationDialog
                            v-if="item.is_active"
                            :name="item.question_pattern"
                            :form="
                                ChatbotKnowledgeActivationController.form(
                                    item.id,
                                )
                            "
                            description="This record will remain available to administrators and can be reactivated later."
                        />
                        <Form
                            v-else
                            v-bind="
                                ChatbotKnowledgeActivationController.form(
                                    item.id,
                                )
                            "
                            :options="{ preserveScroll: true }"
                            v-slot="{ processing }"
                        >
                            <input type="hidden" name="is_active" value="1" />
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="processing"
                            >
                                <Spinner v-if="processing" />
                                <RotateCcw v-else />
                                Reactivate
                            </Button>
                        </Form>
                    </div>
                </article>
            </div>

            <CatalogPagination
                :current-page="knowledge.current_page"
                :last-page="knowledge.last_page"
                :route="ChatbotKnowledgeController.index"
                label="Chatbot knowledge pages"
            />
        </section>
    </main>
</template>
