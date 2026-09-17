import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { update as updateAppearancePreference } from '@/routes/appearance';

const supportedAppearances = new Set(['light', 'dark', 'system']);
const appearance = ref('system');

const normalizeAppearance = (value) =>
    supportedAppearances.has(value) ? value : 'system';

export function updateTheme(value) {
    if (typeof window === 'undefined') {
        return;
    }

    const normalizedAppearance = normalizeAppearance(value);

    if (normalizedAppearance === 'system') {
        const mediaQueryList = window.matchMedia(
            '(prefers-color-scheme: dark)',
        );
        const systemTheme = mediaQueryList.matches ? 'dark' : 'light';
        document.documentElement.classList.toggle(
            'dark',
            systemTheme === 'dark',
        );
    } else {
        document.documentElement.classList.toggle(
            'dark',
            normalizedAppearance === 'dark',
        );
    }
}

const mediaQuery = () => {
    if (typeof window === 'undefined') {
        return null;
    }
    return window.matchMedia('(prefers-color-scheme: dark)');
};
const prefersDark = () => {
    if (typeof window === 'undefined') {
        return false;
    }
    return window.matchMedia('(prefers-color-scheme: dark)').matches;
};
const handleSystemThemeChange = () => {
    if (appearance.value === 'system') {
        updateTheme('system');
    }
};

const synchronizeAppearance = (value) => {
    appearance.value = normalizeAppearance(value);
    updateTheme(appearance.value);
};

export function initializeTheme() {
    if (typeof window === 'undefined') {
        return;
    }

    synchronizeAppearance(document.documentElement.dataset.appearance);
    mediaQuery()?.addEventListener('change', handleSystemThemeChange);

    router.on('navigate', (event) => {
        synchronizeAppearance(event.detail.page.props.appearance);
    });
}

export function useAppearance() {
    const resolvedAppearance = computed(() => {
        if (appearance.value === 'system') {
            return prefersDark() ? 'dark' : 'light';
        }
        return appearance.value;
    });

    function updateAppearance(value) {
        const previousAppearance = appearance.value;

        synchronizeAppearance(value);

        router.patch(
            updateAppearancePreference.url(),
            { appearance: appearance.value },
            {
                preserveScroll: true,
                onError: () => synchronizeAppearance(previousAppearance),
            },
        );
    }

    return {
        appearance,
        resolvedAppearance,
        updateAppearance,
    };
}
