<script setup>
import { Head, router, useHttp } from '@inertiajs/vue3';
import { ref } from 'vue';
import PcBuildTemplateController from '@/actions/App/Http/Controllers/Administration/PcBuildTemplateController';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps({
    templates: { type: Array, required: true },
    products: { type: Array, required: true },
});
const name = ref('Budget Gaming PC');
const description = ref('');
const budget = ref('');
const active = ref(false);
const productId = ref('');
const quantity = ref(1);
const components = ref([]);
const editVersion = ref(null);
const editingId = ref(null);
const notice = ref('');
const request = useHttp({ name: '', description: null, budget: null, is_active: false, edit_version: null, components: [] });

function reset() {
    name.value = 'Budget Gaming PC';
    description.value = '';
    budget.value = '';
    active.value = false;
    components.value = [];
    editingId.value = null;
    editVersion.value = null;
    notice.value = '';
}

function edit(template) {
    editingId.value = template.id;
    editVersion.value = template.edit_version;
    name.value = template.name;
    description.value = template.description ?? '';
    budget.value = template.budget ?? '';
    active.value = template.is_active;
    components.value = template.components.map((component) => ({ ...component }));
}

function addComponent() {
    const product = props.products.find((item) => item.id === Number(productId.value));
    if (!product) return;
    const sameType = components.value.filter((item) => item.component_type === product.component_type).length;
    components.value.push({
        key: `${product.component_type}-${sameType + 1}`,
        product_id: product.id,
        component_type: product.component_type,
        quantity: Number(quantity.value),
        configuration: {},
    });
    productId.value = '';
    quantity.value = 1;
}

async function save() {
    request.name = name.value.trim();
    request.description = description.value || null;
    request.budget = budget.value || null;
    request.is_active = active.value;
    request.components = components.value;
    request.edit_version = editingId.value ? editVersion.value : null;
    try {
        let saved;
        if (editingId.value) {
            saved = await request.put(PcBuildTemplateController.update.url({ pcBuildTemplate: editingId.value }));
        } else {
            saved = await request.post(PcBuildTemplateController.store.url());
        }
        editingId.value = saved.id;
        editVersion.value = saved.edit_version;
        notice.value = 'Template saved.';
        router.reload({ only: ['templates'] });
    } catch {
        notice.value = 'Could not save the template. Check that every product has an approved specification.';
    }
}

async function remove(template) {
    try {
        await request.delete(PcBuildTemplateController.destroy.url({ pcBuildTemplate: template.id }));
        router.reload({ only: ['templates'] });
        notice.value = 'Template deleted.';
        if (editingId.value === template.id) reset();
    } catch {
        notice.value = 'Could not delete the template.';
    }
}
</script>

<template>
    <Head title="PC build templates" />
    <main class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8">
        <PageHeader title="PC build templates" heading-id="pc-build-templates-heading" />
        <p class="text-muted-foreground max-w-3xl text-sm leading-6">Create customer starter builds from existing products with approved specifications. Inactive templates remain drafts until reviewed and published.</p>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
            <section class="surface-panel border-border bg-card border" aria-label="Existing templates">
                <div class="border-border flex items-center justify-between gap-3 border-b p-5">
                    <h2 class="font-semibold">Starter templates</h2>
                    <Button variant="outline" @click="reset">New template</Button>
                </div>
                <ul class="divide-border divide-y">
                    <li v-for="template in templates" :key="template.uuid" class="flex flex-wrap items-center justify-between gap-4 p-5">
                        <div>
                            <p class="font-semibold">{{ template.name }}</p>
                            <p class="text-muted-foreground mt-1 text-sm">{{ template.components.length }} components · {{ template.is_active ? 'Published' : 'Draft' }}</p>
                        </div>
                        <div class="flex gap-2">
                            <Button size="sm" variant="outline" @click="edit(template)">Edit</Button>
                            <Button size="sm" variant="destructive" @click="remove(template)">Delete</Button>
                        </div>
                    </li>
                    <li v-if="templates.length === 0" class="text-muted-foreground p-8 text-center">No templates yet. Catalog specifications must be approved before products can be used.</li>
                </ul>
            </section>

            <section class="surface-panel border-border bg-card h-fit border p-5" aria-labelledby="template-editor-heading">
                <h2 id="template-editor-heading" class="text-lg font-semibold">{{ editingId ? 'Edit template' : 'Create template' }}</h2>
                <form class="mt-5 grid gap-4" @submit.prevent="save">
                    <div class="grid gap-2"><Label for="template-name">Template name</Label><Input id="template-name" v-model="name" maxlength="120" required /></div>
                    <div class="grid gap-2"><Label for="template-description">Description</Label><textarea id="template-description" v-model="description" maxlength="500" rows="3" class="border-input bg-background rounded-md border p-3 text-sm"></textarea></div>
                    <div class="grid gap-2"><Label for="template-budget">Suggested budget (PHP)</Label><Input id="template-budget" v-model="budget" type="number" min="0" step="100" /></div>
                    <label class="flex items-center gap-2 text-sm"><input v-model="active" type="checkbox" class="size-4 accent-red-600" /> Publish to customers</label>
                    <div class="grid gap-2">
                        <Label for="template-product">Reviewed catalog product</Label>
                        <div class="flex gap-2">
                            <select id="template-product" v-model="productId" class="border-input bg-background h-10 min-w-0 flex-1 rounded-md border px-3 text-sm">
                                <option value="">Select a product</option>
                                <option v-for="product in products" :key="product.id" :value="String(product.id)">{{ product.name }} · {{ product.component_type }}</option>
                            </select>
                            <Input v-model.number="quantity" aria-label="Product quantity" type="number" min="1" max="999" class="w-20" />
                            <Button type="button" variant="outline" @click="addComponent">Add</Button>
                        </div>
                    </div>
                    <ul class="grid gap-2" aria-label="Template components">
                        <li v-for="(component, index) in components" :key="component.key" class="flex items-center justify-between rounded-md border p-2 text-sm">
                            <span>{{ products.find((product) => product.id === component.product_id)?.name ?? component.product_id }} × {{ component.quantity }}</span>
                            <Button type="button" size="sm" variant="ghost" @click="components.splice(index, 1)">Remove</Button>
                        </li>
                    </ul>
                    <p v-if="notice" role="status" class="text-sm text-muted-foreground">{{ notice }}</p>
                    <Button type="submit" :disabled="request.processing || components.length === 0">Save template</Button>
                </form>
            </section>
        </div>
    </main>
</template>
