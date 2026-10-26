<script setup>
import { Form, Link, router, usePage } from '@inertiajs/vue3';
import { Heart, LoaderCircle } from '@lucide/vue';
import { onBeforeUnmount, ref, watch } from 'vue';
import WishlistController from '@/actions/App/Http/Controllers/WishlistController';
import { login } from '@/routes';
import { Button } from '@/components/ui/button';

const props = defineProps({
    productId: { type: Number, required: true },
    productName: { type: String, default: 'this product' },
    isWishlisted: { type: Boolean, default: false },
    canWishlist: { type: Boolean, default: false },
    compact: { type: Boolean, default: false },
});
const page = usePage();
const isSaved = ref(props.isWishlisted);
watch(() => props.isWishlisted, (value) => { isSaved.value = value; });
const removeFlashListener = router.on('flash', (event) => {
    const mutation = event.detail?.flash?.wishlistMutation;
    if (mutation?.product_id === props.productId) {
        isSaved.value = mutation.is_wishlisted;
    }
});
onBeforeUnmount(removeFlashListener);
</script>

<template>
    <Form v-if="canWishlist" v-bind="isSaved ? WishlistController.destroy.form(productId) : WishlistController.store.form()" v-slot="{ processing, errors }" preserve-scroll>
        <input v-if="!isSaved" type="hidden" name="product_id" :value="productId" />
        <Button type="submit" variant="outline" :size="compact ? 'icon' : 'default'" :disabled="processing" :aria-label="isSaved ? `Remove ${productName} from wishlist` : `Add ${productName} to wishlist`" :aria-pressed="isSaved" class="bg-background/90">
            <LoaderCircle v-if="processing" class="size-4 animate-spin" aria-hidden="true" />
            <Heart v-else :class="['size-4', isSaved && 'fill-current text-destructive']" aria-hidden="true" />
            <span v-if="!compact">{{ isSaved ? 'Saved' : 'Save' }}</span>
            <span v-if="processing" class="sr-only">Updating wishlist</span>
        </Button>
        <p v-if="errors.product_id" role="alert" class="text-destructive mt-1 text-xs">{{ errors.product_id }}</p>
    </Form>
    <Button v-else-if="!page.props.auth?.user" as-child variant="outline" :size="compact ? 'icon' : 'default'" :aria-label="compact ? 'Log in to save to wishlist' : undefined">
        <Link :href="login()"><Heart class="size-4" aria-hidden="true" /><span v-if="!compact">Log in to save</span></Link>
    </Button>
</template>
