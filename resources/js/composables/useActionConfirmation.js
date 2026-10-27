import { onBeforeUnmount, ref } from 'vue';

export function useActionConfirmation() {
    const confirmation = ref(null);
    let resolveAnswer;
    function answer(value) {
        confirmation.value = null;
        resolveAnswer?.(value);
        resolveAnswer = null;
    }
    function confirm(options) {
        answer(false);
        confirmation.value = options;
        return new Promise((resolve) => {
            resolveAnswer = resolve;
        });
    }
    onBeforeUnmount(() => answer(false));
    return { confirmation, confirm, answer };
}
