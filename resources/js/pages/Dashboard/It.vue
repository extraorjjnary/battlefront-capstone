<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, Logs, UserRoundCog } from '@lucide/vue';
import { computed } from 'vue';
import AuditLogController from '@/actions/App/Http/Controllers/Administration/AuditLogController';
import TeamController from '@/actions/App/Http/Controllers/Administration/TeamController';
import PageHeader from '@/components/PageHeader.vue';
import { dashboard as dashboardRoute } from '@/routes';

const page = usePage();
const permissions = computed(() => page.props.auth.can ?? {});
const quickLinks = computed(() => [
    ...(permissions.value.manageUsers
        ? [
              {
                  title: 'Team management',
                  description: 'Manage Staff and Manager accounts.',
                  href: TeamController.index(),
                  icon: UserRoundCog,
              },
          ]
        : []),
    ...(permissions.value.viewAudit
        ? [
              {
                  title: 'Activity log',
                  description: 'Review administration activity.',
                  href: AuditLogController.index(),
                  icon: Logs,
              },
          ]
        : []),
]);

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboardRoute() }],
    },
});
</script>

<template>
    <div class="contents">
        <Head title="Dashboard" />
        <main
            class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8"
        >
            <PageHeader
                :title="`Welcome, ${page.props.auth.user.name}`"
                heading-id="it-dashboard-heading"
                description="Manage team access and review system activity."
            />

            <section
                v-if="quickLinks.length"
                aria-labelledby="it-tools-heading"
            >
                <h2 id="it-tools-heading" class="mb-4 text-xl font-semibold">
                    Administration tools
                </h2>
                <div class="grid gap-4 md:grid-cols-2">
                    <Link
                        v-for="item in quickLinks"
                        :key="item.title"
                        :href="item.href"
                        class="surface-panel border-border bg-card hover:bg-accent focus-visible:ring-ring flex items-start gap-3 border p-5 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <component
                            :is="item.icon"
                            class="text-primary-ink mt-0.5 size-5 shrink-0"
                            aria-hidden="true"
                        />
                        <span class="min-w-0 flex-1">
                            <span class="font-semibold">{{ item.title }}</span>
                            <span
                                class="text-muted-foreground mt-1 block text-sm"
                            >
                                {{ item.description }}
                            </span>
                        </span>
                        <ArrowRight
                            class="text-muted-foreground mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                    </Link>
                </div>
            </section>
        </main>
    </div>
</template>
