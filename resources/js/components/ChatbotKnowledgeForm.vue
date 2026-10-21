<script setup>
import { Form, Link, useHttp } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import { ref, watch } from 'vue';
import ChatbotKnowledgePreviewController from '@/actions/App/Http/Controllers/Administration/ChatbotKnowledgePreviewController';
import ChatbotKnowledgeController from '@/actions/App/Http/Controllers/Administration/ChatbotKnowledgeController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';

const props = defineProps({
    knowledge: { type: Object, default: null },
    form: { type: Object, required: true },
    submitLabel: { type: String, required: true },
});

const question = ref(props.knowledge?.question_pattern ?? '');
const preview = useHttp({ message: '' });
const previewResult = ref(null);
const previewError = ref('');

watch(
    [() => props.knowledge?.id, () => props.knowledge?.question_pattern],
    ([, value]) => {
        question.value = value ?? '';
    },
);

watch(question, () => {
    previewResult.value = null;
    previewError.value = '';
    preview.clearErrors();
});

async function checkQuestion() {
    if (preview.processing) {
        return;
    }
    preview.clearErrors();
    const tested = question.value.trim();
    preview.message = tested;
    previewResult.value = null;
    previewError.value = '';
    try {
        const result = await preview.post(
            ChatbotKnowledgePreviewController.url(),
        );
        if (question.value.trim() === tested) {
            previewResult.value = result;
        }
    } catch {
        if (question.value.trim() === tested && !preview.errors.message) {
            previewError.value =
                'Unable to check this question. Please try again.';
        } else if (question.value.trim() !== tested) {
            preview.clearErrors();
        }
    }
}
</script>

<template>
    <Form v-bind="form" class="space-y-6" v-slot="{ errors, processing }">
        <p class="text-muted-foreground text-sm">
            Maintain approved Battlefront support guidance. Current product
            facts, branch details, personal orders, and payment or fulfillment
            rules are answered automatically.
        </p>
        <p
            v-if="knowledge && !knowledge.eligible_for_faq"
            class="text-muted-foreground text-sm"
        >
            Needs review: {{ knowledge.eligibility_reason }} Saving valid
            guidance converts this record to FAQ.
        </p>

        <div class="grid gap-2">
            <Label for="question-pattern">Question</Label>
            <Input
                id="question-pattern"
                name="question_pattern"
                v-model="question"
                maxlength="255"
                placeholder="How do I search for products?"
                :aria-invalid="Boolean(errors.question_pattern)"
                required
                autofocus
            />
            <p class="text-muted-foreground text-xs">
                Use one support question. Related wording can match; equally
                strong matches require clarification.
            </p>
            <InputError :message="errors.question_pattern" />
            <Button
                type="button"
                variant="outline"
                class="justify-self-start"
                :disabled="preview.processing || !question.trim()"
                @click="checkQuestion"
            >
                <Spinner v-if="preview.processing" />
                Check question
            </Button>
            <InputError :message="preview.errors.message || previewError" />
            <div aria-live="polite" :aria-busy="preview.processing">
                <template v-if="previewResult">
                    <p class="text-sm font-medium">
                        {{
                            previewResult.eligible_for_faq
                                ? 'Eligible for a support FAQ'
                                : 'Handled outside managed FAQs'
                        }}
                    </p>
                    <p class="text-muted-foreground mt-1 text-sm">
                        {{ previewResult.explanation }}
                    </p>
                    <p
                        v-for="match in previewResult.matches"
                        :key="match.id"
                        class="mt-2 text-sm"
                    >
                        Saved match: {{ match.question_pattern }}
                    </p>
                </template>
            </div>
        </div>

        <div class="grid gap-2">
            <Label for="response-template">Approved answer</Label>
            <Textarea
                id="response-template"
                name="response_template"
                :default-value="knowledge?.response_template"
                rows="8"
                placeholder="Enter the approved response content."
                :aria-invalid="Boolean(errors.response_template)"
                required
            />
            <InputError :message="errors.response_template" />
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <Button :disabled="processing">
                <Spinner v-if="processing" />
                <Save v-else />
                {{ submitLabel }}
            </Button>
            <Button type="button" variant="outline" as-child>
                <Link :href="ChatbotKnowledgeController.index()">Cancel</Link>
            </Button>
        </div>
    </Form>
</template>
