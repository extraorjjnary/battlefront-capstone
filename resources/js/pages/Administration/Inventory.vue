<script setup>
import { Form, Head, Link } from "@inertiajs/vue3";
import { Boxes, ChevronLeft, ChevronRight, Save, TriangleAlert } from "@lucide/vue";
import InventoryController from "@/actions/App/Http/Controllers/Administration/InventoryController";
import InputError from "@/components/InputError.vue";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Spinner } from "@/components/ui/spinner";

const props = defineProps({
    products: { type: Object, required: true },
    filters: { type: Object, required: true },
    low_stock_count: { type: Number, required: true },
});

const dateFormatter = new Intl.DateTimeFormat("en-PH", {
    dateStyle: "medium",
    timeStyle: "short",
});

function formatLastUpdated(value) {
    return value ? dateFormatter.format(new Date(value)) : "Not available";
}

function inventoryPage(page) {
    return InventoryController.index({
        query: {
            page,
            stock: props.filters.stock === "low" ? "low" : undefined,
        },
    });
}

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: "Inventory",
                href: InventoryController.index(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Inventory" />

    <main class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-8 p-6 lg:p-10">
        <section class="border-border bg-card relative overflow-hidden border p-6">
            <div class="bg-primary absolute inset-y-0 left-0 w-1"></div>
            <div class="flex items-start gap-4">
                <span
                    class="bg-secondary text-primary flex size-11 shrink-0 items-center justify-center rounded-md"
                >
                    <Boxes class="size-5" />
                </span>
                <div>
                    <p class="text-primary text-xs font-semibold tracking-widest uppercase">
                        Sagay City operation
                    </p>
                    <h1 class="mt-2 text-2xl font-bold tracking-tight sm:text-3xl">
                        Inventory management
                    </h1>
                    <p class="text-muted-foreground mt-2 max-w-2xl text-sm leading-6">
                        Review current product stock and maintain the quantity and reorder level
                        used by Battlefront operations. Stock is low when its quantity falls below
                        its reorder level.
                    </p>
                </div>
            </div>
        </section>

        <section aria-labelledby="inventory-ledger-heading">
            <div class="mb-4 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-muted-foreground text-sm">
                        {{ products.total }}
                        {{ filters.stock === "low" ? "low-stock" : "" }}
                        products
                    </p>
                    <h2 id="inventory-ledger-heading" class="text-xl font-semibold">
                        Stock ledger
                    </h2>
                </div>

                <div
                    class="flex flex-wrap items-center gap-2"
                    role="group"
                    aria-label="Filter inventory by stock status"
                >
                    <Button
                        :variant="filters.stock === 'all' ? 'default' : 'outline'"
                        size="sm"
                        as-child
                    >
                        <Link
                            :href="InventoryController.index()"
                            :aria-current="filters.stock === 'all' ? 'page' : undefined"
                            preserve-scroll
                        >
                            All products
                        </Link>
                    </Button>
                    <Button
                        :variant="filters.stock === 'low' ? 'default' : 'outline'"
                        size="sm"
                        as-child
                    >
                        <Link
                            :href="
                                InventoryController.index({
                                    query: { stock: 'low' },
                                })
                            "
                            :aria-current="filters.stock === 'low' ? 'page' : undefined"
                            preserve-scroll
                        >
                            <TriangleAlert />
                            Low stock
                            <span aria-hidden="true">{{ low_stock_count }}</span>
                            <span class="sr-only"> {{ low_stock_count }} products </span>
                        </Link>
                    </Button>
                </div>
            </div>

            <div
                v-if="products.data.length === 0"
                class="border-border bg-card flex min-h-48 items-center justify-center border p-6 text-center"
            >
                <div>
                    <template v-if="filters.stock === 'low'">
                        <TriangleAlert class="text-muted-foreground mx-auto size-8" />
                        <p class="mt-3 font-medium">No low-stock products</p>
                        <p class="text-muted-foreground mt-1 text-sm">
                            Initialized products are currently at or above their reorder levels.
                        </p>
                    </template>
                    <template v-else>
                        <Boxes class="text-muted-foreground mx-auto size-8" />
                        <p class="mt-3 font-medium">No products available</p>
                        <p class="text-muted-foreground mt-1 text-sm">
                            Products will appear here after they are added to the catalog.
                        </p>
                    </template>
                </div>
            </div>

            <div v-else class="border-border bg-card divide-border divide-y border">
                <article
                    v-for="product in products.data"
                    :key="product.id"
                    class="grid gap-5 p-5 xl:grid-cols-[minmax(0,1.3fr)_minmax(0,2fr)] xl:items-center"
                >
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="truncate font-semibold">
                                {{ product.name }}
                            </h3>
                            <Badge :variant="product.is_active ? 'secondary' : 'outline'">
                                {{ product.is_active ? "Active" : "Inactive" }}
                            </Badge>
                            <Badge
                                v-if="product.is_low_stock"
                                variant="outline"
                                class="border-destructive/50 text-destructive"
                            >
                                <TriangleAlert />
                                Low stock
                            </Badge>
                        </div>
                        <p class="text-muted-foreground mt-1 text-sm">
                            {{ product.brand }} · {{ product.category }}
                        </p>
                        <p v-if="product.inventory" class="text-muted-foreground mt-3 text-xs">
                            Last updated
                            {{ formatLastUpdated(product.inventory.last_updated) }}
                        </p>
                    </div>

                    <Form
                        v-if="product.inventory"
                        v-bind="InventoryController.update.form(product.inventory.id)"
                        :options="{ preserveScroll: true }"
                        class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-start"
                        v-slot="{ errors, processing }"
                    >
                        <div class="grid gap-2">
                            <Label :for="`quantity-${product.inventory.id}`">
                                Current quantity
                            </Label>
                            <Input
                                :id="`quantity-${product.inventory.id}`"
                                name="quantity"
                                type="number"
                                min="0"
                                max="4294967295"
                                step="1"
                                inputmode="numeric"
                                :default-value="product.inventory.quantity"
                                :aria-invalid="Boolean(errors.quantity)"
                                required
                            />
                            <InputError :message="errors.quantity" />
                        </div>

                        <div class="grid gap-2">
                            <Label :for="`reorder-level-${product.inventory.id}`">
                                Reorder level
                            </Label>
                            <Input
                                :id="`reorder-level-${product.inventory.id}`"
                                name="reorder_level"
                                type="number"
                                min="0"
                                max="4294967295"
                                step="1"
                                inputmode="numeric"
                                :default-value="product.inventory.reorder_level"
                                :aria-invalid="Boolean(errors.reorder_level)"
                                required
                            />
                            <InputError :message="errors.reorder_level" />
                        </div>

                        <Button class="sm:mt-7" :disabled="processing">
                            <Spinner v-if="processing" />
                            <Save v-else />
                            Update stock
                        </Button>
                    </Form>

                    <div
                        v-else
                        class="border-border bg-muted/40 flex min-h-20 items-center justify-between gap-4 border p-4"
                    >
                        <div>
                            <Badge variant="outline">Not initialized</Badge>
                            <p class="text-muted-foreground mt-2 text-sm">
                                This product does not have an inventory record yet.
                            </p>
                        </div>
                        <span class="text-muted-foreground text-sm">No stock to update</span>
                    </div>
                </article>
            </div>

            <nav
                v-if="products.last_page > 1"
                aria-label="Inventory pages"
                class="mt-5 flex items-center justify-between gap-4"
            >
                <Button v-if="products.current_page > 1" variant="outline" size="sm" as-child>
                    <Link :href="inventoryPage(products.current_page - 1)" preserve-scroll>
                        <ChevronLeft />
                        Previous
                    </Link>
                </Button>
                <Button v-else variant="outline" size="sm" disabled>
                    <ChevronLeft />
                    Previous
                </Button>
                <p class="text-muted-foreground text-sm">
                    Page {{ products.current_page }} of {{ products.last_page }}
                </p>
                <Button
                    v-if="products.current_page < products.last_page"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link :href="inventoryPage(products.current_page + 1)" preserve-scroll>
                        Next
                        <ChevronRight />
                    </Link>
                </Button>
                <Button v-else variant="outline" size="sm" disabled>
                    Next
                    <ChevronRight />
                </Button>
            </nav>
        </section>
    </main>
</template>
