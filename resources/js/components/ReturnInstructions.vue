<script setup>
defineProps({ instructions: { type: Object, required: true } });
</script>

<template>
    <aside
        class="border-border min-w-0 space-y-1 border-l-2 pl-3 text-sm wrap-anywhere"
        aria-label="Return instructions"
    >
        <p class="font-medium">{{ instructions.label }}</p>
        <template v-if="instructions.branch">
            <p>
                {{ instructions.branch.name }} · {{ instructions.branch.city }}
            </p>
            <p>{{ instructions.branch.address }}</p>
            <p v-if="instructions.branch.contact_number">
                {{ instructions.branch.contact_number }}
            </p>
        </template>
        <p v-else>Contact Battlefront to confirm the return address.</p>
        <p class="text-muted-foreground">{{ instructions.message }}</p>
    </aside>
</template>
