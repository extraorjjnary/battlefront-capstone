<script setup>
import { http, useHttp } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import TeamEmailVerificationController from '@/actions/App/Http/Controllers/Administration/TeamEmailVerificationController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps({
    verification: { type: Object, default: null },
    error: { type: String, default: '' },
});
const emit = defineEmits(['verified']);
const state = ref(props.verification);
const email = ref(props.verification?.email ?? '');
const code = ref('');
const message = ref('');
const error = ref('');
const now = ref(Date.now());
const limitedUntil = ref(0);
const sending = useHttp({ email: '' });
const confirming = ref(false);
const clearing = useHttp({ email: '' });
const busy = computed(
    () => sending.processing || confirming.value || clearing.processing,
);
const normalizedEmail = computed(() => email.value.trim().toLowerCase());
let epoch = 0;
let requestedEmail = '';
let timer;
onMounted(() => {
    timer = setInterval(() => {
        now.value = Date.now();
    }, 1000);
});
onUnmounted(() => {
    clearInterval(timer);
    epoch++;
});

const verified = computed(
    () =>
        state.value?.email === normalizedEmail.value &&
        state.value?.status === 'verified' &&
        Date.parse(state.value.verified_until) > now.value &&
        !busy.value,
);
const cooldown = computed(() =>
    Math.max(
        0,
        Math.ceil(
            (Math.max(
                limitedUntil.value,
                state.value?.email === normalizedEmail.value
                    ? Date.parse(state.value.resend_available_at) || 0
                    : 0,
            ) -
                now.value) /
                1000,
        ),
    ),
);
const codeExpired = computed(
    () =>
        state.value?.expires_at &&
        Date.parse(state.value.expires_at) <= now.value,
);
const proofExpired = computed(
    () => state.value?.status === 'verified' && !verified.value && !busy.value,
);
watch(verified, (value) => emit('verified', value), { immediate: true });
watch(
    () => props.verification,
    (value) => {
        if (value && email.value && value.email !== normalizedEmail.value)
            return;
        state.value = value;
        if (value) {
            email.value = value.email;
        }
    },
);

function reset() {
    epoch++;
    state.value = null;
    email.value = '';
    code.value = '';
    message.value = '';
    error.value = '';
}
defineExpose({ reset });

async function changeEmail(value) {
    const previousEmail = state.value?.email ?? requestedEmail;
    email.value = value;
    if (previousEmail && previousEmail !== normalizedEmail.value) {
        epoch++;
        state.value = null;
        code.value = '';
        message.value = '';
        error.value = '';
        requestedEmail = '';
        clearing.email = previousEmail;
        try {
            await clearing.delete(
                TeamEmailVerificationController.destroy.url(),
            );
        } catch {
            error.value =
                'Unable to clear the previous verification. Verify the current email before creating the account.';
        }
    }
}

async function requestCode(confirm = false) {
    if (busy.value || !normalizedEmail.value) {
        return;
    }
    const currentEpoch = ++epoch;
    const target = normalizedEmail.value;
    requestedEmail = target;
    error.value = '';
    message.value = '';
    function receive(payload) {
        if (epoch !== currentEpoch || normalizedEmail.value !== target) {
            return;
        }
        if (Object.hasOwn(payload, 'data')) {
            state.value = payload.data;
        }
        if (payload.retry_after > 0) {
            limitedUntil.value = Date.now() + payload.retry_after * 1000;
        }
        message.value = payload.message ?? '';
        error.value =
            payload.errors?.code?.[0] ?? payload.errors?.email?.[0] ?? '';
    }
    try {
        if (confirm) {
            confirming.value = true;
            const response = await http.getClient().request({
                method: 'post',
                url: TeamEmailVerificationController.verify.url(),
                data: { email: target, code: code.value },
                headers: { Accept: 'application/json' },
            });
            receive(JSON.parse(response.data));
        } else {
            sending.email = target;
            const response = await sending.post(
                TeamEmailVerificationController.store.url(),
                {
                    onSuccess: receive,
                    onError: (errors) =>
                        receive({
                            errors: Object.fromEntries(
                                Object.entries(errors).map(([key, value]) => [
                                    key,
                                    Array.isArray(value) ? value : [value],
                                ]),
                            ),
                        }),
                },
            );
            if (!response) return;
        }
        if (epoch === currentEpoch) {
            code.value = '';
        }
    } catch (exception) {
        if (exception.response?.data) {
            try {
                receive(JSON.parse(exception.response.data));
            } catch {
                // Network proxies may return an HTML error instead of JSON.
            }
        }
        if (epoch === currentEpoch && !message.value && !error.value) {
            error.value = 'Verification is unavailable. Please try again.';
        }
    } finally {
        confirming.value = false;
    }
}
</script>

<template>
    <div class="space-y-3">
        <div class="space-y-2">
            <Label for="team-email">Email</Label>
            <Input
                id="team-email"
                name="email"
                type="email"
                required
                class="min-h-11"
                :model-value="email"
                @update:model-value="changeEmail"
                aria-describedby="team-email-guidance team-email-error"
            />
            <InputError
                id="team-email-error"
                :message="error || (!verified ? props.error : '')"
            />
        </div>
        <p id="team-email-guidance" class="text-muted-foreground text-sm">
            The team member must personally enter the emailed code here before
            you create their account.
        </p>
        <p v-if="verified" role="status" class="text-sm font-medium">
            Email verified. You can create the account within ten minutes.
        </p>
        <template v-else>
            <p
                v-if="state?.delivery_status === 'failed'"
                role="alert"
                class="text-destructive text-sm"
            >
                Email delivery failed. Wait for the resend timer and try again.
            </p>
            <p
                v-else-if="
                    state?.delivery_status === 'rate_limited' && cooldown > 0
                "
                role="status"
                class="text-muted-foreground text-sm"
            >
                Code requests are temporarily limited. Wait for the resend
                timer.
            </p>
            <Button
                type="button"
                variant="outline"
                class="min-h-11"
                :disabled="busy || !normalizedEmail || cooldown > 0"
                @click="requestCode()"
            >
                {{
                    sending.processing
                        ? 'Sending…'
                        : cooldown > 0
                          ? `Resend in ${cooldown}s`
                          : state
                            ? 'Resend code'
                            : 'Verify email'
                }}
            </Button>
            <div
                v-if="state?.expires_at && state.status !== 'verified'"
                class="space-y-2"
            >
                <Label for="team-email-code">Verification code</Label>
                <Input
                    id="team-email-code"
                    v-model="code"
                    type="text"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    maxlength="6"
                    placeholder="000000"
                    class="min-h-11 tracking-widest"
                    @keydown.enter.prevent="requestCode(true)"
                />
                <p class="text-muted-foreground text-sm">
                    {{ state.attempts_remaining }} attempts remaining. Codes
                    expire after five minutes.
                </p>
                <Button
                    type="button"
                    class="min-h-11"
                    :disabled="
                        busy ||
                        code.length !== 6 ||
                        codeExpired ||
                        state.attempts_remaining <= 0
                    "
                    @click="requestCode(true)"
                    >{{ confirming ? 'Checking…' : 'Confirm code' }}</Button
                >
            </div>
            <p
                v-if="proofExpired"
                role="alert"
                class="text-destructive text-sm"
            >
                Email verification expired. Verify the email again.
            </p>
            <p
                v-else-if="codeExpired"
                role="alert"
                class="text-destructive text-sm"
            >
                The code expired. Request a new code.
            </p>
            <p
                v-else-if="state?.attempts_remaining === 0"
                role="alert"
                class="text-destructive text-sm"
            >
                All five attempts have been used. Request a new code.
            </p>
        </template>
        <p
            v-if="message"
            role="status"
            aria-live="polite"
            class="text-muted-foreground text-sm"
        >
            {{ message }}
        </p>
    </div>
</template>
