<script setup>
import { computed } from 'vue';
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router';
import { useAuthStore } from './stores/auth';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

const isLogin = computed(() => route.meta.public === true);

/*
 * Navigation is filtered by role purely for clarity. The server refuses anything a
 * manager should not reach, so hiding a link only avoids offering a door that will
 * not open — it is not what keeps data safe.
 */
const links = computed(() => [
    { name: 'dashboard', label: 'Dashboard', show: true },
    { name: 'roster', label: 'Roster', show: auth.canAdminister },
    { name: 'timesheets', label: 'Timesheets', show: auth.canAdminister },
    { name: 'variance', label: 'Planned vs actual', show: auth.canAdminister },
    { name: 'employees', label: 'Employees', show: auth.canAdminister },
    { name: 'outlets', label: 'Outlets & codes', show: auth.canAdminister },
    { name: 'anomalies', label: 'Anomalies', show: auth.canAdminister },
    { name: 'corrections', label: 'Corrections', show: auth.canAdminister },
    { name: 'accounts', label: 'Accounts', show: auth.isOwner },
].filter((link) => link.show));

async function signOut() {
    await auth.logout();
    router.push({ name: 'login' });
}
</script>

<template>
    <!-- The login screen stands alone: no nav, nothing to mislead. -->
    <div v-if="isLogin" class="min-h-dvh bg-stone-50">
        <RouterView />
    </div>

    <div v-else class="min-h-dvh bg-stone-50">
        <header class="border-b border-stone-200 bg-white">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-3">
                <div>
                    <p class="text-sm font-semibold text-stone-900">Dars Coffee</p>
                    <p class="text-xs text-stone-500">Attendance</p>
                </div>

                <nav class="flex flex-wrap items-center gap-1">
                    <RouterLink
                        v-for="link in links"
                        :key="link.name"
                        :to="{ name: link.name }"
                        class="rounded-lg px-3 py-1.5 text-sm text-stone-600 hover:bg-stone-100 hover:text-stone-900"
                        active-class="bg-amber-50 text-amber-900"
                    >
                        {{ link.label }}
                    </RouterLink>
                </nav>

                <div class="flex items-center gap-3">
                    <div class="text-right">
                        <p class="text-sm text-stone-800">{{ auth.user?.name }}</p>
                        <p class="text-xs text-stone-500">{{ auth.user?.role_label }}</p>
                    </div>

                    <button
                        type="button"
                        class="rounded-lg border border-stone-300 px-3 py-1.5 text-xs font-medium text-stone-700"
                        @click="signOut"
                    >
                        Sign out
                    </button>
                </div>
            </div>

            <!--
                A manager's scope matters enough to state outright. With three outlets
                and one manager, silence would leave her unsure whether she is seeing
                everything or only part of it.
            -->
            <div
                v-if="auth.user && !auth.isOwner"
                class="border-t border-amber-100 bg-amber-50 px-4 py-1.5 text-center text-xs text-amber-900"
            >
                You can see
                <strong>{{ auth.visibleOutletIds?.length ?? 0 }} outlet(s)</strong> —
                ask the owner if you need access to more.
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-4 py-6">
            <RouterView />
        </main>
    </div>
</template>
