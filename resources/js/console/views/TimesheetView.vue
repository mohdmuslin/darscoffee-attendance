<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useTimesheetStore } from '../stores/timesheets';
import { useOutletStore } from '../stores/outlets';
import { businessDate, hm, lastDays, toIsoDate } from '../lib/format';

const store = useTimesheetStore();
const outlets = useOutletStore();
const router = useRouter();

/*
 * Named `period`, not `window`. A ref called `window` shadows the global object inside
 * this component, which silently breaks anything reaching for window.location.
 */
const period = ref(lastDays(7));
const outletId = ref('');
const includeInactive = ref(false);

onMounted(async () => {
    if (outlets.outlets.length === 0) {
        await outlets.load();
    }

    await store.setRange(period.value.from, period.value.to);
});

async function reload() {
    await store.setRange(period.value.from, period.value.to);
}

/** Quick range buttons. Most weeks are looked at as a whole, not as arbitrary dates. */
function setPreset(days) {
    period.value = lastDays(days);
    reload();
}

/**
 * Today to the end of the current month, which is the window someone closing off payroll
 * actually wants.
 */
function setThisMonth() {
    const now = new Date();
    const first = new Date(now.getFullYear(), now.getMonth(), 1);

    period.value = { from: toIsoDate(first), to: toIsoDate(now) };
    reload();
}

const filters = computed(() => ({
    outlet_id: outletId.value || undefined,
    active_only: ! includeInactive.value,
}));

async function refreshWithFilters() {
    await store.loadSummary(filters.value);
}

async function exportCsv() {
    await store.downloadCsv({
        ...filters.value,
        // Blank fields rather than nulls: axios drops undefined params, which is what the
        // server expects for "no filter".
        outlet_id: outletId.value || undefined,
    });
}
</script>

<template>
    <div>
        <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold text-stone-900">Timesheets</h1>
                <p class="text-sm text-stone-500">
                    Hours worked, by employee, for the period selected.
                </p>
            </div>

            <button
                type="button"
                class="rounded-lg bg-amber-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                :disabled="store.loading"
                @click="exportCsv"
            >
                Export CSV
            </button>
        </div>

        <!-- Filters -->
        <div class="mb-5 rounded-xl border border-stone-200 bg-white p-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs text-stone-500" for="from">From</label>
                    <input
                        id="from"
                        v-model="period.from"
                        type="date"
                        class="rounded-lg border border-stone-300 px-3 py-2 text-sm"
                        @change="reload"
                    />
                </div>

                <div>
                    <label class="mb-1 block text-xs text-stone-500" for="to">To</label>
                    <input
                        id="to"
                        v-model="period.to"
                        type="date"
                        class="rounded-lg border border-stone-300 px-3 py-2 text-sm"
                        @change="reload"
                    />
                </div>

                <div>
                    <label class="mb-1 block text-xs text-stone-500" for="outlet">Outlet</label>
                    <select
                        id="outlet"
                        v-model="outletId"
                        class="rounded-lg border border-stone-300 px-3 py-2 text-sm"
                        @change="refreshWithFilters"
                    >
                        <option value="">All outlets</option>
                        <option v-for="o in outlets.outlets" :key="o.id" :value="o.id">
                            {{ o.name }}
                        </option>
                    </select>
                </div>

                <label class="flex items-center gap-2 pb-2 text-sm text-stone-700">
                    <input v-model="includeInactive" type="checkbox" @change="refreshWithFilters" />
                    Include former staff
                </label>
            </div>

            <div class="mt-3 flex flex-wrap gap-2">
                <button
                    v-for="preset in [7, 14, 30]"
                    :key="preset"
                    type="button"
                    class="rounded-lg border border-stone-300 px-3 py-1.5 text-xs text-stone-700 hover:bg-stone-50"
                    @click="setPreset(preset)"
                >
                    Last {{ preset }} days
                </button>

                <button
                    type="button"
                    class="rounded-lg border border-stone-300 px-3 py-1.5 text-xs text-stone-700 hover:bg-stone-50"
                    @click="setThisMonth"
                >
                    This month
                </button>
            </div>
        </div>

        <p v-if="store.error" class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ store.error }}
        </p>

        <!-- Totals -->
        <div v-if="store.summary" class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-xl border border-stone-200 bg-white p-4">
                <p class="text-xs text-stone-500">Total worked</p>
                <p class="mt-1 text-2xl font-semibold text-stone-900">
                    {{ store.summary.totals.worked_label }}
                </p>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white p-4">
                <p class="text-xs text-stone-500">Overtime</p>
                <p class="mt-1 text-2xl font-semibold text-stone-900">
                    {{ store.summary.totals.overtime_label }}
                </p>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white p-4">
                <p class="text-xs text-stone-500">Employees</p>
                <p class="mt-1 text-2xl font-semibold text-stone-900">
                    {{ store.summary.employees.length }}
                </p>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white p-4">
                <p class="text-xs text-stone-500">Needs attention</p>
                <!--
                    Incomplete days, counted rather than hidden. An open segment's hours are
                    still accumulating, so a total containing one is not final — and a manager
                    who does not know that will pay the wrong figure.
                -->
                <p
                    class="mt-1 text-2xl font-semibold"
                    :class="store.summary.totals.incomplete_days > 0 ? 'text-amber-900' : 'text-stone-900'"
                >
                    {{ store.summary.totals.incomplete_days }}
                </p>
                <p class="text-xs text-stone-400">
                    {{ store.summary.totals.incomplete_days > 0 ? 'incomplete days' : 'days' }}
                </p>
            </div>
        </div>

        <!-- Per-employee table -->
        <div class="overflow-hidden rounded-xl border border-stone-200 bg-white">
            <table class="w-full text-sm">
                <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-stone-500">
                    <tr>
                        <th class="px-4 py-2 font-medium">Employee</th>
                        <th class="px-4 py-2 font-medium">Days</th>
                        <th class="px-4 py-2 font-medium">Worked</th>
                        <th class="px-4 py-2 font-medium">Overtime</th>
                        <th class="px-4 py-2 font-medium">Incomplete</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-stone-100">
                    <tr
                        v-for="row in store.summary?.employees ?? []"
                        :key="row.employee_id"
                        class="hover:bg-stone-50"
                    >
                        <td class="px-4 py-2">
                            <p class="text-stone-900">{{ row.name }}</p>
                            <p class="text-xs text-stone-400">{{ row.employee_code }}</p>
                        </td>
                        <td class="px-4 py-2 text-stone-700">{{ row.days_worked }}</td>
                        <td class="px-4 py-2 font-medium text-stone-900">{{ row.worked_label }}</td>
                        <td class="px-4 py-2 text-stone-700">
                            {{ row.overtime_seconds > 0 ? hm(row.overtime_seconds) : '—' }}
                        </td>
                        <td class="px-4 py-2">
                            <span
                                v-if="row.incomplete_days > 0"
                                class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-900"
                            >
                                {{ row.incomplete_days }}
                            </span>
                            <span v-else class="text-stone-300">—</span>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <button
                                type="button"
                                class="text-xs text-amber-900 underline"
                                @click="router.push({ name: 'employee-timesheet', params: { id: row.employee_id } })"
                            >
                                View
                            </button>
                        </td>
                    </tr>

                    <tr v-if="(store.summary?.employees ?? []).length === 0">
                        <td colspan="6" class="px-4 py-6 text-center text-sm text-stone-500">
                            No hours recorded in this period.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="mt-3 text-xs text-stone-400">
            Showing {{ businessDate(store.summary?.from) }} to
            {{ businessDate(store.summary?.to) }}. Overtime is measured on hours
            <strong>worked</strong>, so breaks do not count towards it.
        </p>
    </div>
</template>
