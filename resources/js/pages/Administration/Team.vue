<script setup>
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import TeamController from '@/actions/App/Http/Controllers/Administration/TeamController';
import RolePermissionController from '@/actions/App/Http/Controllers/Administration/RolePermissionController';
import OrderController from '@/actions/App/Http/Controllers/Administration/OrderController';
import WorkQueueController from '@/actions/App/Http/Controllers/Administration/WorkQueueController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps({ users: Object, permissions: Array, grants: Array, availableRoles: Array, canManagePermissions: Boolean, roleVersions: Object, filters: Object, canViewOrders: Boolean, canAssignOrders: Boolean });
const search = ref(props.filters.q ?? '');
const status = ref(props.filters.status ?? 'all');
function applyFilters() {
    router.get(TeamController.index(), { q: search.value || undefined, status: status.value === 'all' ? undefined : status.value });
}
const hasGrant = (role, code) => props.grants.some((grant) => grant.role === role && grant.permission === code);
const roleLabel = (role) => role === 'it' ? 'IT Administrator' : role ? role.charAt(0).toUpperCase() + role.slice(1) : 'Unassigned';
const permissionLabels = {
    'orders.view': 'View orders',
    'orders.process': 'Update order status',
    'orders.assign': 'Assign orders',
    'inventory.view': 'View inventory',
    'inventory.request': 'Request stock changes',
    'inventory.approve': 'Approve stock changes',
    'inventory.reorder': 'Manage reorders',
    'products.manage': 'Manage product details',
    'products.price': 'Manage product prices',
    'catalog.delete': 'Delete products and categories',
    'customers.view': 'View customers',
    'reports.view': 'View sales reports',
    'reports.export': 'Export reports',
    'reports.profit': 'View profit reports',
    'forecast.manage': 'Manage forecasts',
    'returns.request': 'Request returns',
    'returns.approve': 'Approve returns',
    'returns.record_payment': 'Record return payments',
    'returns.dispatch': 'Dispatch returns',
    'returns.resolve': 'Resolve returns',
    'returns.reconcile': 'Reconcile returns',
    'suppliers.manage': 'Manage suppliers',
    'staff_reports.view': 'View staff reports',
    'audit.view': 'View audit log',
    'audit.export': 'Export audit log',
    'users.manage': 'Manage user accounts',
    'permissions.manage': 'Manage role permissions',
};
const permissionLabel = (permission) => permissionLabels[permission.code] ?? permission.name.replaceAll('.', ' ');

defineOptions({ layout: { breadcrumbs: [{ title: 'Team', href: TeamController.index() }] } });
</script>

<template>
    <Head title="Team access" />
    <main class="mx-auto flex w-full max-w-6xl flex-col gap-8 p-6 lg:p-10">
        <div>
            <p class="text-primary text-xs font-semibold tracking-widest uppercase">Access control</p>
            <h1 class="mt-2 text-2xl font-bold">Team access</h1>
            <p class="text-muted-foreground mt-2 text-sm">Each team member has an individual account. Saved role or access changes apply the next time they use the system.</p>
        </div>

        <section class="border-border bg-card space-y-4 border p-5">
            <h2 class="text-lg font-semibold">Add team member</h2>
            <Form v-bind="TeamController.store.form()" v-slot="{ errors, processing }" class="grid gap-4 md:grid-cols-2">
                <div class="space-y-2"><Label for="team-name">Name</Label><Input id="team-name" name="name" required /><InputError :message="errors.name" /></div>
                <div class="space-y-2"><Label for="team-email">Email</Label><Input id="team-email" name="email" type="email" required /><InputError :message="errors.email" /></div>
                <div class="space-y-2"><Label for="team-role">Role</Label><select id="team-role" name="role" class="border-border bg-background w-full border p-2" required><option v-for="role in availableRoles" :key="role" :value="role">{{ roleLabel(role) }}</option></select><InputError :message="errors.role" /></div>
                <div class="space-y-2"><Label for="team-password">Initial password</Label><Input id="team-password" name="password" type="password" autocomplete="new-password" required /><InputError :message="errors.password" /></div>
                <div class="space-y-2"><Label for="team-password-confirmation">Confirm initial password</Label><Input id="team-password-confirmation" name="password_confirmation" type="password" autocomplete="new-password" required /></div>
                <div class="flex items-end"><Button type="submit" :disabled="processing">Create account</Button></div>
            </Form>
            <p class="text-muted-foreground text-sm">Share the initial password privately. The assigned role controls which features the team member can access. Password changes are not currently required at first sign-in.</p>
        </section>

        <section class="space-y-4">
            <h2 class="text-lg font-semibold">Team members</h2>
            <form class="border-border bg-card flex flex-wrap items-end gap-3 border p-4" @submit.prevent="applyFilters">
                <div class="space-y-1"><Label for="team-search">Name or email</Label><Input id="team-search" v-model="search" maxlength="100" /></div>
                <div class="space-y-1"><Label for="team-status">Status</Label><select id="team-status" v-model="status" class="border-border bg-background block border p-2"><option value="all">All</option><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                <Button type="submit" variant="outline">Apply filters</Button>
            </form>
            <p v-if="users.data.length === 0" class="text-muted-foreground text-sm">No administrator accounts.</p>
            <div v-for="user in users.data" :key="user.id" class="border-border bg-card flex flex-wrap items-center justify-between gap-4 border p-4">
                <div><p class="font-medium">{{ user.name }}</p><p class="text-muted-foreground text-sm">{{ user.email }} · {{ roleLabel(user.role) }}</p></div>
                <div v-if="user.open_order_count" class="w-full text-sm"><p>{{ user.open_order_count }} unfinished assigned {{ user.open_order_count === 1 ? 'order' : 'orders' }}. Deactivation takes effect immediately; these orders will need reassignment.</p><div v-if="canViewOrders" class="mt-1 flex flex-wrap gap-2"><Link v-for="orderId in user.open_order_ids" :key="orderId" :href="OrderController.show(orderId)" class="text-primary underline">BF-{{ String(orderId).padStart(6, '0') }}</Link><Link v-if="canAssignOrders" :href="WorkQueueController.index()" class="text-primary underline">Work queue</Link></div></div>
                <Form v-if="user.can_edit" v-bind="TeamController.update.form(user.id)" v-slot="{ errors, processing }" class="flex flex-wrap items-center gap-2">
                    <select name="role" :value="user.role" class="border-border bg-background border p-2"><option v-for="role in availableRoles" :key="role" :value="role">{{ roleLabel(role) }}</option></select>
                    <select name="is_active" :value="user.is_active ? '1' : '0'" class="border-border bg-background border p-2"><option value="1">Active</option><option value="0">Deactivated</option></select>
                    <Button type="submit" variant="outline" :disabled="processing">Save</Button>
                    <InputError :message="errors.user || errors.role || errors.is_active" />
                </Form>
                <p v-else class="text-muted-foreground text-sm">Only an owner can edit Owner and IT Administrator accounts.</p>
            </div>
            <div v-if="users.last_page > 1" class="flex justify-between text-sm"><Link v-if="users.prev_page_url" :href="users.prev_page_url" class="text-primary underline">Previous</Link><span v-else></span><Link v-if="users.next_page_url" :href="users.next_page_url" class="text-primary underline">Next</Link></div>
        </section>

        <section v-if="canManagePermissions" class="space-y-4">
            <h2 class="text-lg font-semibold">Role permissions</h2>
            <p class="text-muted-foreground text-sm">These permissions apply to every team member assigned to that role.</p>
            <Form v-for="role in ['staff', 'manager']" :key="role" v-bind="RolePermissionController.update.form(role)" v-slot="{ errors, processing }" class="border-border bg-card space-y-4 border p-5">
                <h3 class="font-semibold capitalize">{{ role }}</h3>
                <input type="hidden" name="edit_version" :value="roleVersions[role]" />
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <label v-for="permission in permissions.filter((entry) => !['users.manage', 'permissions.manage', 'audit.export'].includes(entry.code))" :key="permission.code" class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="permissions[]" :value="permission.code" :checked="hasGrant(role, permission.code)" />{{ permissionLabel(permission) }}
                    </label>
                </div>
                <InputError :message="errors.permissions" />
                <InputError :message="errors.edit_version" />
                <Button type="submit" :disabled="processing">Save permissions</Button>
            </Form>
        </section>
    </main>
</template>
