import { onMounted, shallowRef, watch } from 'vue';
import { useAppearance } from '@/composables/useAppearance';

export function useChartTheme() {
    const { resolvedAppearance } = useAppearance();
    const colors = shallowRef({});

    function refreshColors() {
        if (typeof window === 'undefined') {
            return;
        }

        const styles = window.getComputedStyle(document.documentElement);
        const color = (token) => styles.getPropertyValue(token).trim();
        colors.value = {
            primary: color('--chart-1'),
            secondary: color('--chart-2'),
            highlight: color('--brand-highlight'),
            fill: color('--chart-fill'),
            muted: color('--chart-muted'),
            text: color('--muted-foreground'),
            foreground: color('--foreground'),
            border: color('--border'),
            surface: color('--card'),
        };
    }

    onMounted(refreshColors);
    watch(resolvedAppearance, refreshColors, { flush: 'post' });

    return colors;
}
