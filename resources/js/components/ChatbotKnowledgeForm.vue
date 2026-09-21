<script setup>
import { Form, Link } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import { ref } from 'vue';
import ChatbotKnowledgeController from '@/actions/App/Http/Controllers/Administration/ChatbotKnowledgeController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';

const props = defineProps({
    knowledge: { type: Object, default: null },
    categories: { type: Array, required: true },
    form: { type: Object, required: true },
    submitLabel: { type: String, required: true },
});

const category = ref(
    props.knowledge?.category ?? props.categories[0]?.value ?? '',
);
</script>

<template>
    <Form v-bind="form" class="space-y-6" v-slot="{ errors, processing }">
        <div class="grid gap-6 sm:grid-cols-[minmax(0,1fr)_10rem]">
            <div class="grid gap-2">
                <Label for="chatbot-category">Category</Label>
                <input type="hidden" name="category" :value="category" />
                <Select v-model="category">
                    <SelectTrigger
                        id="chatbot-category"
                        class="w-full"
                        :aria-invalid="Boolean(errors.category)"
                    >
                        <SelectValue placeholder="Select a category" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in categories"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="errors.category" />
            </div>

            <div class="grid gap-2">
                <Label for="priority">Priority</Label>
                <Input
                    id="priority"
                    name="priority"
                    type="number"
                    step="1"
                    :default-value="knowledge?.priority ?? 0"
                    :aria-invalid="Boolean(errors.priority)"
                    required
                />
                <InputError :message="errors.priority" />
            </div>
        </div>

        <div class="grid gap-2">
            <Label for="question-pattern">Question pattern</Label>
            <Input
                id="question-pattern"
                name="question_pattern"
                :default-value="knowledge?.question_pattern"
                maxlength="255"
                placeholder="What are your store hours?"
                :aria-invalid="Boolean(errors.question_pattern)"
                required
                autofocus
            />
            <p class="text-muted-foreground text-xs">
                Store the approved question wording or pattern only; matching
                logic is handled separately.
            </p>
            <InputError :message="errors.question_pattern" />
        </div>

        <div class="grid gap-2">
            <Label for="response-template">Response template</Label>
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
