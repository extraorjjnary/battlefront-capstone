<script setup>
import { Form, Head } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { send, verify } from '@/routes/verification';

const props = defineProps({
    verification: { type: Object, required: true },
    status: { type: String, default: '' },
});

defineOptions({
    layout: {
        title: 'Verify your email',
        description: 'Confirm your email before logging in to Battlefront.',
    },
});

const now = ref(Date.now());
let timer;
onMounted(() => {
    timer = setInterval(() => {
        now.value = Date.now();
    }, 1000);
});
onUnmounted(() => clearInterval(timer));

const resendSeconds = computed(() =>
    Math.max(
        0,
        Math.ceil(
            ((Date.parse(props.verification.resend_available_at) || 0) -
                now.value) /
                1000,
        ),
    ),
);
const expired = computed(
    () =>
        props.verification.expires_at &&
        Date.parse(props.verification.expires_at) <= now.value,
);
const exhausted = computed(() => props.verification.attempts_remaining <= 0);
const deliveryFailed = computed(
    () => props.verification.delivery_status === 'failed',
);
const rateLimited = computed(
    () =>
        props.verification.delivery_status === 'rate_limited' &&
        resendSeconds.value > 0,
);
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head title="Verify email" />

        <div class="space-y-2 text-center text-sm">
            <p class="text-muted-foreground">
                Check the inbox for
                <span class="text-foreground font-medium">{{
                    verification.masked_email
                }}</span>
                and enter your six-digit code below. Each code expires after
                five minutes.
            </p>
            <p v-if="status" role="status" aria-live="polite">{{ status }}</p>
            <p v-if="rateLimited" role="status">
                Code requests are temporarily limited. Wait for the resend timer
                before requesting another code.
            </p>
            <p v-else-if="deliveryFailed" class="text-destructive" role="alert">
                Email delivery was unsuccessful. You can request another code
                when the timer ends.
            </p>
            <p v-else-if="exhausted" class="text-destructive" role="alert">
                You have used all five attempts. Request a new code to continue.
            </p>
            <p v-else-if="expired" class="text-destructive" role="alert">
                This code has expired. Request a new code to continue.
            </p>
        </div>

        <Form
            v-bind="verify.form()"
            :reset-on-success="['code']"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-4"
        >
            <div class="grid gap-2">
                <Label for="code">Verification code</Label>
                <Input
                    id="code"
                    name="code"
                    type="text"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    pattern="[0-9]{6}"
                    minlength="6"
                    maxlength="6"
                    placeholder="000000"
                    required
                    autofocus
                    :aria-invalid="Boolean(errors.code)"
                    aria-describedby="code-guidance code-error"
                    class="text-center text-xl tracking-[0.4em]"
                />
                <p id="code-guidance" class="text-muted-foreground text-sm">
                    {{ verification.attempts_remaining }} attempt{{
                        verification.attempts_remaining === 1 ? '' : 's'
                    }}
                    remaining. Never share your code.
                </p>
                <InputError id="code-error" :message="errors.code" />
            </div>
            <Button
                type="submit"
                :disabled="
                    processing ||
                    exhausted ||
                    Boolean(expired) ||
                    deliveryFailed ||
                    !verification.expires_at
                "
            >
                <Spinner v-if="processing" />
                Verify email
            </Button>
        </Form>

        <Form
            v-bind="send.form()"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-2"
        >
            <Button
                type="submit"
                variant="outline"
                class="w-full"
                :disabled="processing || resendSeconds > 0"
            >
                <Spinner v-if="processing" />
                {{
                    resendSeconds > 0
                        ? `Resend code in ${resendSeconds}s`
                        : 'Resend code'
                }}
            </Button>
            <InputError :message="errors.code" />
        </Form>

        <TextLink :href="login()" class="text-center text-sm"
            >Return to login</TextLink
        >
    </div>
</template>
