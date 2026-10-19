import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { update as updateAppearancePreference } from '@/routes/appearance';

const appearance = ref('light');
let accountAppearance;

const normalizeAppearance = (value) => (value === 'dark' ? 'dark' : 'light');
const guestAppearanceKey = 'battlefront_guest_appearance';

function guestAppearance() {
    try {
        return normalizeAppearance(
            window.localStorage.getItem(guestAppearanceKey),
        );
    } catch {
        return 'light';
    }
}

const pageAppearance = (props) =>
    props.auth?.user
        ? normalizeAppearance(props.appearance)
        : guestAppearance();

export function updateTheme(value) {
    if (typeof window === 'undefined') {
        return;
    }

    document.documentElement.classList.toggle(
        'dark',
        normalizeAppearance(value) === 'dark',
    );
}

const synchronizeAppearance = (value) => {
    appearance.value = normalizeAppearance(value);
    updateTheme(appearance.value);
};

export function initializeTheme(props) {
    if (typeof window === 'undefined') {
        return;
    }

    accountAppearance = {
        userId: props.auth?.user?.id ?? null,
        value: pageAppearance(props),
    };
    synchronizeAppearance(accountAppearance.value);

    router.on('navigate', (event) => {
        const { props } = event.detail.page;
        const userId = props.auth?.user?.id ?? null;

        if (accountAppearance.userId !== userId) {
            accountAppearance = {
                userId,
                value: pageAppearance(props),
            };
        }

        // History and prefetch responses can carry an older account preference.
        synchronizeAppearance(accountAppearance.value);
    });
}

export function useAppearance() {
    const page = usePage();

    const currentAppearance =
        typeof window === 'undefined'
            ? ref(normalizeAppearance(page.props.appearance))
            : appearance;
    const resolvedAppearance = computed(() => currentAppearance.value);

    function updateAppearance(value) {
        const previousAppearance = appearance.value;
        const selection = {
            userId: page.props.auth?.user?.id ?? null,
            value: normalizeAppearance(value),
        };
        accountAppearance = selection;

        synchronizeAppearance(selection.value);

        if (selection.userId === null) {
            try {
                window.localStorage.setItem(
                    guestAppearanceKey,
                    selection.value,
                );
            } catch {
                // Keep switching usable when browser storage is unavailable.
            }

            return;
        }

        router.patch(
            updateAppearancePreference.url(),
            { appearance: appearance.value },
            {
                preserveScroll: true,
                onError: () => {
                    if (accountAppearance !== selection) {
                        return;
                    }

                    selection.value = previousAppearance;
                    synchronizeAppearance(previousAppearance);
                },
            },
        );
    }

    return {
        appearance: currentAppearance,
        resolvedAppearance,
        updateAppearance,
    };
}
