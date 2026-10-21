<script setup>
import { computed, ref } from 'vue';
import { Input } from '@/components/ui/input';

const props = defineProps({
    products: { type: Array, required: true },
    id: { type: String, required: true },
    name: { type: String, default: 'product_id' },
});

const search = ref('');
const selectedId = ref('');
const filteredProducts = computed(() => {
    const query = search.value.trim().toLocaleLowerCase();

    if (!query) {
        return props.products;
    }

    const matches = props.products.filter((product) =>
        `${product.name} ${product.product_code}`
            .toLocaleLowerCase()
            .includes(query),
    );

    const selected = props.products.find(
        (product) => String(product.id) === selectedId.value,
    );

    return selected && !matches.includes(selected)
        ? [selected, ...matches]
        : matches;
});
</script>

<template>
    <div class="grid gap-2">
        <Input
            v-model="search"
            type="search"
            :aria-label="`Search ${id}`"
            placeholder="Search product name or code"
            autocomplete="off"
        />
        <select
            :id="id"
            v-model="selectedId"
            :name="name"
            required
            class="border-border bg-background w-full border p-2"
        >
            <option value="">Select product</option>
            <option
                v-for="product in filteredProducts"
                :key="product.id"
                :value="product.id"
            >
                {{ product.product_code }} · {{ product.name }} ·
                {{ product.available_quantity }} available
            </option>
        </select>
        <p
            v-if="filteredProducts.length === 0"
            class="text-muted-foreground text-xs"
            role="status"
        >
            No products match this search.
        </p>
    </div>
</template>
