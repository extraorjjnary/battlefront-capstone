import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
export function initializeFlashToast() {
    router.on('flash', (event) => {
        const flash = event.detail?.flash;
        const data = flash?.toast;
        if (!data) {
            return;
        }
        toast[data.type](data.message);
    });
}
