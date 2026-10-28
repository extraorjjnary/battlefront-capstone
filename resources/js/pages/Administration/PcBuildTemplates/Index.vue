<script setup>
import { Head, router, useHttp } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import PcBuildTemplateController from '@/actions/App/Http/Controllers/Administration/PcBuildTemplateController';
import PageHeader from '@/components/PageHeader.vue';
import InputError from '@/components/InputError.vue';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import { useActionConfirmation } from '@/composables/useActionConfirmation';
import { useUnsavedEditor } from '@/composables/useUnsavedEditor';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { componentLabels } from '../ComponentSpecifications/specificationFields';
import { nextComponentKey, templatePayload } from './templateEditor';

const props = defineProps({ templates: Array, products: Array });
const { confirmation, confirm, answer } = useActionConfirmation();
const search = ref('');
const productSearch = ref('');
const category = ref('processor');
const name = ref('');
const description = ref('');
const budget = ref('');
const active = ref(false);
const productId = ref('');
const quantity = ref(1);
const components = ref([]);
const editVersion = ref(null);
const editingId = ref(null);
const notice = ref('');
const request = useHttp({
    name: '',
    description: null,
    budget: null,
    is_active: false,
    edit_version: null,
    components: [],
});
const snapshot = () =>
    JSON.stringify([
        name.value,
        description.value,
        budget.value,
        components.value,
    ]);
const baseline = ref(snapshot());
const dirty = computed(() => snapshot() !== baseline.value);
const { mayDiscard } = useUnsavedEditor(dirty, confirm);
const visibleTemplates = computed(() =>
    props.templates.filter((template) =>
        template.name.toLowerCase().includes(search.value.trim().toLowerCase()),
    ),
);
const visibleProducts = computed(() =>
    props.products.filter(
        (product) =>
            product.component_type === category.value &&
            !components.value.some(
                (component) => component.product_id === product.id,
            ) &&
            `${product.name} ${product.product_code}`
                .toLowerCase()
                .includes(productSearch.value.trim().toLowerCase()),
    ),
);
const fanPositions = computed(
    () =>
        props.products.find(
            (product) =>
                product.id ===
                components.value.find((item) => item.component_type === 'case')
                    ?.product_id,
        )?.fan_mounting_positions ?? [],
);
async function edit(template = null, force = false) {
    if (request.processing || (!force && !(await mayDiscard()))) return;
    editingId.value = template?.id ?? null;
    editVersion.value = template?.edit_version ?? null;
    name.value = template?.name ?? '';
    description.value = template?.description ?? '';
    budget.value = template?.budget ?? '';
    active.value = template?.is_active ?? false;
    components.value = JSON.parse(
        JSON.stringify(template?.components ?? []),
    ).map((component) => ({
        ...component,
        configuration: { ...component.configuration },
    }));
    productId.value = '';
    quantity.value = 1;
    notice.value = '';
    request.clearErrors();
    baseline.value = snapshot();
    await nextTick();
    document.getElementById('template-editor-heading')?.focus();
}
function addComponent() {
    const product = props.products.find(
        (item) => item.id === Number(productId.value),
    );
    if (
        !product ||
        components.value.some(
            (component) => component.product_id === product.id,
        ) ||
        !Number.isInteger(Number(quantity.value)) ||
        quantity.value < 1 ||
        quantity.value > 999
    )
        return;
    components.value.push({
        key: nextComponentKey(components.value, product.component_type),
        product_id: product.id,
        product_name: product.name,
        product_code: product.product_code,
        component_type: product.component_type,
        quantity: Number(quantity.value),
        configuration: {},
    });
    productId.value = '';
    quantity.value = 1;
}
async function save(publish = active.value) {
    if (request.processing) return;
    notice.value = '';
    request.name = name.value.trim();
    request.description = description.value || null;
    request.budget = budget.value || null;
    request.is_active = publish;
    request.components = templatePayload(components.value);
    request.edit_version = editingId.value ? editVersion.value : null;
    try {
        const saved = editingId.value
            ? await request.put(
                  PcBuildTemplateController.update.url({
                      pcBuildTemplate: editingId.value,
                  }),
              )
            : await request.post(PcBuildTemplateController.store.url());
        editingId.value = saved.id;
        editVersion.value = saved.edit_version;
        active.value = saved.is_active;
        baseline.value = snapshot();
        notice.value = publish
            ? 'Template saved and published. Customers see it when current stock and compatibility checks pass.'
            : 'Draft saved. This template is hidden from customers.';
        router.reload({ only: ['templates'] });
    } catch {
        notice.value =
            'The template was not saved. Resolve the errors below and try again.';
    }
}
async function remove(template) {
    if (
        request.processing ||
        !(await confirm({
            title: `Delete ${template.name}?`,
            description:
                'This permanently deletes the starter template. Customer saved builds are unchanged.',
            label: 'Delete template',
            destructive: true,
        }))
    )
        return;
    try {
        await request.delete(
            PcBuildTemplateController.destroy.url({
                pcBuildTemplate: template.id,
            }),
        );
        if (editingId.value === template.id) await edit(null, true);
        router.reload({ only: ['templates'] });
        notice.value = 'Template deleted.';
    } catch {
        notice.value = 'The template could not be deleted. Try again.';
    }
}
async function reloadTemplate() {
    if (!(await mayDiscard())) return;
    router.reload({
        only: ['templates', 'products'],
        onSuccess: () =>
            edit(
                props.templates.find(
                    (template) => template.id === editingId.value,
                ),
                true,
            ),
    });
}
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8"
    >
        <Head title="PC build templates" />
        <PageHeader
            title="PC build templates"
            heading-id="pc-build-templates-heading"
        />
        <p class="text-muted-foreground max-w-3xl text-sm leading-6">
            Prepare a starter build from reviewed catalog products. Save a draft
            while working, then publish it when compatibility checks pass.
        </p>
        <div class="grid items-start gap-6 xl:grid-cols-[22rem_minmax(0,1fr)]">
            <section
                class="border-border bg-card grid gap-4 rounded-lg border p-4"
                aria-label="Starter templates"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="font-semibold">Starter templates</h2>
                    <Button
                        variant="outline"
                        :disabled="request.processing"
                        @click="edit()"
                        >Create template</Button
                    >
                </div>
                <Label for="template-search">Find a template</Label
                ><Input
                    id="template-search"
                    v-model="search"
                    placeholder="Template name"
                />
                <ul class="divide-border divide-y">
                    <li
                        v-for="template in visibleTemplates"
                        :key="template.id"
                        class="grid gap-3 py-4"
                    >
                        <p class="font-semibold">{{ template.name }}</p>
                        <p class="text-muted-foreground text-sm">
                            {{ template.components.length }} component
                            selections
                        </p>
                        <Badge variant="outline" class="w-fit">{{
                            template.is_active ? 'Published' : 'Draft'
                        }}</Badge>
                        <div class="flex gap-2">
                            <Button
                                size="sm"
                                variant="outline"
                                :disabled="request.processing"
                                @click="edit(template)"
                                >Edit template</Button
                            ><Button
                                size="sm"
                                variant="ghost"
                                :disabled="request.processing"
                                @click="remove(template)"
                                >Delete</Button
                            >
                        </div>
                    </li>
                    <li
                        v-if="!visibleTemplates.length"
                        class="text-muted-foreground py-6 text-sm"
                    >
                        {{
                            templates.length
                                ? 'No matching templates. Try another name.'
                                : 'No starter templates yet. Create one using reviewed products.'
                        }}
                    </li>
                </ul>
            </section>
            <section
                class="border-border bg-card min-w-0 rounded-lg border p-5 sm:p-6"
                aria-labelledby="template-editor-heading"
            >
                <h2
                    id="template-editor-heading"
                    tabindex="-1"
                    class="text-lg font-semibold"
                >
                    {{ editingId ? 'Edit template' : 'Create template' }}
                </h2>
                <p class="text-muted-foreground mt-1 text-sm">
                    {{
                        active
                            ? 'Published — saved changes must pass compatibility checks.'
                            : 'Draft — hidden from customers.'
                    }}
                </p>
                <form class="mt-5 grid gap-5" @submit.prevent="save()">
                    <fieldset
                        :disabled="request.processing"
                        class="grid min-w-0 gap-4"
                    >
                        <div class="grid gap-2">
                            <Label for="template-name">Template name</Label
                            ><Input
                                id="template-name"
                                v-model="name"
                                maxlength="120"
                                required
                                placeholder="For example: Student PC"
                            /><InputError :message="request.errors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="template-description">Description</Label
                            ><textarea
                                id="template-description"
                                v-model="description"
                                maxlength="500"
                                rows="3"
                                class="border-input bg-background rounded-md border p-3 text-sm"
                            ></textarea
                            ><InputError
                                :message="request.errors.description"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="template-budget"
                                >Suggested budget (PHP)</Label
                            ><Input
                                id="template-budget"
                                v-model="budget"
                                type="number"
                                min="0"
                                step="100"
                            /><InputError :message="request.errors.budget" />
                        </div>
                        <div class="border-border grid gap-3 border-t pt-5">
                            <h3 class="font-semibold">Choose components</h3>
                            <Label for="template-category"
                                >Component category</Label
                            ><select
                                id="template-category"
                                v-model="category"
                                class="border-input bg-background h-10 rounded-md border px-3 text-sm"
                                @change="productId = ''"
                            >
                                <option
                                    v-for="(label, value) in componentLabels"
                                    :key="value"
                                    :value="value"
                                >
                                    {{ label }}
                                </option>
                            </select>
                            <Label for="product-search"
                                >Find a reviewed product</Label
                            ><Input
                                id="product-search"
                                v-model="productSearch"
                                placeholder="Product name or code"
                                @update:model-value="productId = ''"
                            />
                            <Label for="template-product"
                                >Reviewed catalog product</Label
                            ><select
                                id="template-product"
                                v-model="productId"
                                class="border-input bg-background h-10 min-w-0 rounded-md border px-3 text-sm"
                            >
                                <option value="">Select a product</option>
                                <option
                                    v-for="product in visibleProducts"
                                    :key="product.id"
                                    :value="String(product.id)"
                                >
                                    {{ product.name }} ·
                                    {{ product.product_code }}
                                </option>
                            </select>
                            <p
                                v-if="!visibleProducts.length"
                                class="text-muted-foreground text-sm"
                            >
                                No reviewed products match. Review
                                specifications or choose another category.
                            </p>
                            <div class="flex flex-wrap items-end gap-3">
                                <div class="grid gap-2">
                                    <Label for="template-quantity"
                                        >Quantity</Label
                                    ><Input
                                        id="template-quantity"
                                        v-model.number="quantity"
                                        type="number"
                                        min="1"
                                        max="999"
                                        step="1"
                                        class="w-24"
                                    />
                                </div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    :disabled="
                                        !productId ||
                                        !Number.isInteger(Number(quantity)) ||
                                        quantity < 1 ||
                                        quantity > 999
                                    "
                                    @click="addComponent"
                                    >Add component</Button
                                >
                            </div>
                        </div>
                        <ul class="grid gap-3" aria-label="Template components">
                            <li
                                v-for="(component, index) in components"
                                :key="component.key"
                                class="border-border grid gap-3 rounded-md border p-4"
                            >
                                <p class="text-sm font-semibold">
                                    {{
                                        component.product_name ??
                                        products.find(
                                            (product) =>
                                                product.id ===
                                                component.product_id,
                                        )?.name ??
                                        'Unavailable product'
                                    }}
                                </p>
                                <p class="text-muted-foreground text-xs">
                                    {{
                                        componentLabels[
                                            component.component_type
                                        ]
                                    }}
                                    ·
                                    {{
                                        component.product_code ??
                                        'Code unavailable'
                                    }}
                                </p>
                                <p
                                    v-if="
                                        !products.some(
                                            (product) =>
                                                product.id ===
                                                component.product_id,
                                        )
                                    "
                                    class="text-destructive text-sm"
                                >
                                    This product is no longer available for
                                    templates. Replace it before saving.
                                </p>
                                <div class="flex flex-wrap items-end gap-3">
                                    <div class="grid gap-2">
                                        <Label
                                            :for="'quantity-' + component.key"
                                            >Quantity</Label
                                        ><Input
                                            :id="'quantity-' + component.key"
                                            v-model.number="component.quantity"
                                            class="w-24"
                                            type="number"
                                            min="1"
                                            max="999"
                                            step="1"
                                            required
                                        />
                                    </div>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        :aria-label="
                                            'Remove ' +
                                            (component.product_name ??
                                                componentLabels[
                                                    component.component_type
                                                ])
                                        "
                                        @click="components.splice(index, 1)"
                                        >Remove component</Button
                                    >
                                </div>
                                <div
                                    v-if="component.component_type === 'fan'"
                                    class="grid gap-2"
                                >
                                    <Label :for="'mount-' + component.key"
                                        >Case mounting position</Label
                                    ><select
                                        :id="'mount-' + component.key"
                                        v-model="
                                            component.configuration
                                                .mounting_position
                                        "
                                        class="border-input bg-background h-10 rounded-md border px-3 text-sm"
                                    >
                                        <option :value="undefined">
                                            Select a position
                                        </option>
                                        <option
                                            v-if="
                                                component.configuration
                                                    .mounting_position &&
                                                !fanPositions.includes(
                                                    component.configuration
                                                        .mounting_position,
                                                )
                                            "
                                            :value="
                                                component.configuration
                                                    .mounting_position
                                            "
                                        >
                                            {{
                                                component.configuration
                                                    .mounting_position
                                            }}
                                            (needs verification)
                                        </option>
                                        <option
                                            v-for="position in fanPositions"
                                            :key="position"
                                            :value="position"
                                        >
                                            {{ position }}
                                        </option>
                                    </select>
                                    <p
                                        v-if="!fanPositions.length"
                                        class="text-muted-foreground text-xs"
                                    >
                                        Choose a case with reviewed fan
                                        positions. Saved settings are preserved.
                                    </p>
                                </div>
                                <InputError
                                    :message="
                                        request.errors[
                                            'components.' + index + '.quantity'
                                        ]
                                    "
                                />
                            </li>
                        </ul>
                    </fieldset>
                    <div
                        v-if="Object.keys(request.errors).length"
                        role="alert"
                        class="border-destructive text-destructive rounded-md border p-4 text-sm"
                    >
                        <p class="font-semibold">Please resolve these issues</p>
                        <ul class="mt-2 list-inside list-disc">
                            <li
                                v-for="(error, key) in request.errors"
                                :key="key"
                            >
                                {{ error }}
                            </li>
                        </ul>
                        <Button
                            v-if="request.errors.edit_version"
                            type="button"
                            variant="outline"
                            class="mt-3"
                            @click="reloadTemplate"
                            >Reload saved template</Button
                        >
                    </div>
                    <p v-if="notice" role="status" class="text-sm">
                        {{ notice }}
                    </p>
                    <div class="flex flex-wrap gap-3">
                        <Button
                            type="submit"
                            :variant="active ? 'default' : 'outline'"
                            :disabled="request.processing || !components.length"
                            >{{
                                request.processing
                                    ? 'Saving…'
                                    : active
                                      ? 'Save changes'
                                      : 'Save draft'
                            }}</Button
                        >
                        <Button
                            v-if="!active"
                            type="submit"
                            :disabled="request.processing || !components.length"
                            @click.prevent="save(true)"
                            >Publish template</Button
                        >
                        <Button
                            v-else
                            type="button"
                            variant="outline"
                            :disabled="request.processing"
                            @click="save(false)"
                            >Unpublish template</Button
                        >
                    </div>
                </form>
            </section>
        </div>
        <ConfirmActionDialog :confirmation="confirmation" @answer="answer" />
    </main>
</template>
