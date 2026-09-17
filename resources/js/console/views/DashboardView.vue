<script setup>
import { computed, onMounted } from 'vue';
import { RouterLink } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { useEmployeeStore } from '../stores/employees';
import { useOutletStore } from '../stores/outlets';

const auth = useAuthStore();
const employees = useEmployeeStore();
const outlets = useOutletStore();

onMounted(async () => {
    /*
     * The dashboard is the first screen after sign-in, so it does the loading. Each
     * request is scoped by the server, which is why a manager's counts differ from
     * the owner's without any client-side filtering.
     */
    await Promise.all([outlets.load(), employees.load()]);
});

/** Things worth acting on, rather than a wall of statistics. */
const attention = computed(() => [
    {
        count: employees.withoutPin.length,
        label: 'employee(s) with no PIN set — they cannot clock in yet',
        to: { name: 'employees' },
        tone: 'warn',
    },
    {
        count: employees.pinLocked.length,
        label: 'employee(s) with a locked PIN after repeated failed attempts',
        to: { name: 'employees' },
        tone: 'warn',
    },
    {
        count: outlets.outlets.filter((outlet) => ! outlet.has_live_token).length,
        label: 'outlet(s) with no active clock-in code',
        to: { name: 'outlets' },
        tone: 'high',
    },
].filter((item) => item.count > 0));
</script>

<template>
    <div>
        <h1 class="text-lg font-semibold text-stone-900">
            Good to see you, {{ auth.user?.name?.split(' ')[0] }}
        </h1>
        <p class="mt-1 text-sm text-stone-500">
            <template v-if="auth.isOwner">
                You can see every outlet.
            </template>
            <template v-else>
                You can see {{ outlets.outlets.length }} outlet(s).
            </template>
        </p>

        <!--
            Anything needing action comes first. On the ordering system an unreviewed
            queue buried below statistics was the thing that got forgotten.
        -->
        <div v-if="attention.length > 0" class="mt-6 space-y-2">
            <RouterLink
                v-for="item in attention"
                :key="item.label"
                :to="item.to"
                class="flex items-center gap-3 rounded-xl border px-4 py-3 text-sm"
                :class="item.tone === 'high'
                    ? 'border-red-200 bg-red-50 text-red-800'
                    : 'border-amber-200 bg-amber-50 text-amber-900'"
            >
                <span class="rounded-full bg-white px-2 py-0.5 text-xs font-semibold">
                    {{ item.count }}
                </span>
                <span>{{ item.label }}</span>
            </RouterLink>
        </div>

        <div v-else-if="!employees.loading" class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            Nothing needs attention right now.
        </div>

        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div class="rounded-xl border border-stone-200 bg-white p-5">
                <p class="text-xs uppercase tracking-wide text-stone-500">Employees</p>
                <p class="mt-1 text-2xl font-semibold text-stone-900">
                    {{ employees.active.length }}
                </p>
                <RouterLink :to="{ name: 'employees' }" class="mt-2 inline-block text-xs text-amber-900 underline">
                    Manage employees
                </RouterLink>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white p-5">
                <p class="text-xs uppercase tracking-wide text-stone-500">Outlets</p>
                <p class="mt-1 text-2xl font-semibold text-stone-900">{{ outlets.outlets.length }}</p>
                <RouterLink :to="{ name: 'outlets' }" class="mt-2 inline-block text-xs text-amber-900 underline">
                    Outlets &amp; codes
                </RouterLink>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white p-5">
                <p class="text-xs uppercase tracking-wide text-stone-500">Clocked in now</p>
                <p class="mt-1 text-2xl font-semibold text-stone-400">—</p>
                <p class="mt-2 text-xs text-stone-500">
                    Arrives with the clock-in flow in Phase 2.
                </p>
            </div>
        </div>

        <p class="mt-6 rounded-xl border border-stone-200 bg-white px-4 py-3 text-xs text-stone-500">
            Timesheets and reports arrive in Phase 3 — the point at which this is worth
            deploying, since capturing hours and correcting mistakes is useful on its own.
        </p>
    </div>
</template>
