<script setup>
import { Form, Head } from '@inertiajs/vue3';
import { store, destroy } from '@/routes/demonstration/rehearsal';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
defineProps({ active: Boolean });
defineOptions({
    layout: {
        title: 'Demonstration rehearsal',
        description:
            'Private fictional testing. Normal login, permissions and email verification still apply.',
    },
});
</script>
<template>
    <div class="space-y-6">
        <Head title="Demonstration rehearsal" />
        <p v-if="active">
            Rehearsal mode is enabled for this browser. It expires after one
            hour and ends on logout. Only supported new records can be eligible
            for cleanup.
        </p>
        <Form
            v-bind="store.form()"
            reset-on-success
            reset-on-error
            v-slot="{ errors, processing }"
            class="space-y-4"
        >
            <label for="password">Private rehearsal credential</label>
            <PasswordInput
                id="password"
                name="password"
                required
                autocomplete="off"
            />
            <InputError :message="errors.password" />
            <label class="flex gap-2"
                ><input
                    type="checkbox"
                    name="fictional"
                    value="1"
                    required
                />All marked activity and offline confirmations are fictional.
                No actual funds or goods will be transferred.</label
            >
            <InputError :message="errors.fictional" />
            <Button :disabled="processing">Enable for this browser</Button>
        </Form>
        <Form v-bind="destroy.form()"
            ><Button variant="outline">Leave rehearsal mode</Button></Form
        >
    </div>
</template>
