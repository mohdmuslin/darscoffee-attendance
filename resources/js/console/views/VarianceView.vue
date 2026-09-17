<script setup>
import { computed, onMounted, ref } from 'vue';
import { useVarianceStore } from '../stores/variance';
import { useOutletStore } from '../stores/outlets';
import { hm, lastDays } from '../lib/format';
import VarianceDetail from '../components/VarianceDetail.vue';

const store = useVarianceStore();
const outlets = useOutletStore();

const period = ref(lastDays(7));
const includeInactive = ref(false);
const openDetail = ref(null);

onMounted(async () => {
    if (outlets.outlets.length === 0) {
        await outlets.load();
    }

    await store.load(period.value.from, period.value.to);
});

async function reload() {
    await store.load(period.value.from, period.value.to);
}

function setPreset(days) {
    period.value = lastDays(days);
    reload();
}

function viewEmployee(row) {
    openDetail.value = row;
    store.loadDetail(row.employee_id);
}

/**
 * How a variance reads.
 *
 * Positive means MORE worked than planned, which is money going out that nobody agreed to
 * — so it is amber, not green. Colour follows what needs attention rather than whether the
 * number is bigger.
 */
function varianceTone(seconds) {
    if (seconds > 0) {
        return 'text-amber-900';
    }

    if (seconds < 0) {
        return 'text-blue-800';
    }

    return 'text-stone-400';
}

function varianceLabel(seconds) {
    if (seconds === 0) {
        return '—';
    }

    return hm(seconds);
}
</script>

<template>
    <div>
        <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold text-stone-900">Planned vs actual</h1>
                <p class="text-sm text-stone-500">
                    What was rostered against what was actually clocked.
                </p>
            </div>

            <button
                type="button"
                class="rounded-lg bg-amber-900 px-4 py-2 text-sm font-medium text-white"
                @click="store.downloadCsv()"
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
                        v-model="store.outletId"
                        class="rounded-lg border border-stone-300 px-3 py-2 text-sm"
                        @change="reload"
                    >
                        <option value="">All outlets</option>
                        <option v-for="o in outlets.outlets" :key="o.id" :value="o.id">{{ o.name }}</option>
                    </select>
                </div>

                <label class="flex items-center gap-2 pb-2 text-sm text-stone-700">
                    <input v-model="includeInactive" type="checkbox" @change="reload" />
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
            </div>
        </div>

        <p v-if="store.error" class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ store.error }}
        </p>

        <!-- Totals -->
        <div v-if="store.totals" class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-xl border border-stone-200 bg-white p-4">
                <p class="text-xs text-stone-500">Planned</p>
                <p class="mt-1 text-2xl font-semibold text-stone-900">{{ store.totals.planned_label }}</p>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white p-4">
                <p class="text-xs text-stone-500">Worked</p>
                <p class="mt-1 text-2xl font-semibold text-stone-900">{{ store.totals.worked_label }}</p>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white p-4">
                <p class="text-xs text-stone-500">Variance</p>
                <p class="mt-1 text-2xl font-semibold" :class="varianceTone(store.totals.variance_seconds)">
                    {{ varianceLabel(store.totals.variance_seconds) }}
                </p>
                <p class="text-xs text-stone-400">
                    {{ store.totals.variance_seconds > 0 ? 'more than planned' : (store.totals.variance_seconds < 0 ? 'less than planned' : 'exactly to plan') }}
                </p>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white p-4">
                <p class="text-xs text-stone-500">Unplanned work</p>
                <p class="mt-1 text-2xl font-semibold" :class="store.totals.adhoc_seconds > 0 ? 'text-amber-900' : 'text-stone-900'">
                    {{ store.totals.adhoc_label }}
                </p>
            </div>
        </div>

        <!--
            The things worth a manager's attention, listed rather than left to be discovered by
            scanning a table. "We also have adhoc work" is the phrase this whole report exists
            to put a number on.
        -->
        <div v-if="store.totals" class="mb-5 flex flex-wrap gap-3">
            <span
                v-if="store.totals.no_show_count > 0"
                class="rounded-lg bg-red-50 px-3 py-1.5 text-sm text-red-800"
            >
                {{ store.totals.no_show_count }} shift(s) nobody turned up for
            </span>

            <span
                v-if="store.totals.partial_count > 0"
                class="rounded-lg bg-amber-50 px-3 py-1.5 text-sm text-amber-900"
            >
                {{ store.totals.partial_count }} shift(s) worked short or long
            </span>

            <span
                v-if="store.totals.unplanned_count > 0"
                class="rounded-lg bg-amber-50 px-3 py-1.5 text-sm text-amber-900"
            >
                {{ store.totals.unplanned_count }} shift(s) worked with nobody rostered
            </span>

            <span
                v-if="store.totals.no_show_count + store.totals.partial_count + store.totals.unplanned_count === 0"
                class="rounded-lg bg-green-50 px-3 py-1.5 text-sm text-green-800"
            >
                {{ store.headline }}
            </span>
        </div>

        <!-- Per-employee table -->
        <div class="overflow-hidden rounded-xl border border-stone-200 bg-white">
            <table class="w-full text-sm">
                <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-stone-500">
                    <tr>
                        <th class="px-4 py-2 font-medium">Employee</th>
                        <th class="px-4 py-2 font-medium">Planned</th>
                        <th class="px-4 py-2 font-medium">Worked</th>
                        <th class="px-4 py-2 font-medium">Variance</th>
                        <th class="px-4 py-2 font-medium">Unplanned</th>
                        <th class="px-4 py-2 font-medium">Issues</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-stone-100">
                    <tr
                        v-for="row in store.employees"
                        :key="row.employee_id"
                        class="hover:bg-stone-50"
                        :class="row.has_variance ? '' : 'text-stone-500'"
                    >
                        <td class="px-4 py-2">
                            <p :class="row.has_variance ? 'text-stone-900' : ''">{{ row.name }}</p>
                            <p class="text-xs text-stone-400">{{ row.employee_code }}</p>
                        </td>
                        <td class="px-4 py-2">{{ row.planned_label }}</td>
                        <td class="px-4 py-2">{{ row.worked_label }}</td>
                        <td class="px-4 py-2 font-medium" :class="varianceTone(row.variance_seconds)">
                            {{ varianceLabel(row.variance_seconds) }}
                        </td>
                        <td class="px-4 py-2">
                            <span v-if="row.adhoc_seconds > 0" class="text-amber-900">{{ row.adhoc_label }}</span>
                            <span v-else class="text-stone-300">—</span>
                        </td>
                        <td class="px-4 py-2">
                            <span
                                v-if="row.no_show_count > 0"
                                class="mr-1 rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-800"
                            >
                                {{ row.no_show_count }} no-show
                            </span>
                            <span
                                v-if="row.partial_count > 0"
                                class="mr-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-900"
                            >
                                {{ row.partial_count }} short/long
                            </span>
                            <span
                                v-if="row.unplanned_count > 0"
                                class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-900"
                            >
                                {{ row.unplanned_count }} unplanned
                            </span>
                            <span
                                v-if="! row.has_variance"
                                class="rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-800"
                            >
                                to plan
                            </span>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <button type="button" class="text-xs text-amber-900 underline" @click="viewEmployee(row)">
                                Details
                            </button>
                        </td>
                    </tr>

                    <tr v-if="store.employees.length === 0 && !store.loading">
                        <td colspan="7" class="px-4 py-6 text-center text-sm text-stone-500">
                            Nothing rostered or worked in this period.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="mt-3 text-xs text-stone-400">
            A shift counts as worked when a punch overlaps it, not from a stored link — moving a
            shift would otherwise make a normal day look like a no-show. Work with nobody
            rostered is listed on its own rather than folded into a nearby shift.
        </p>

        <VarianceDetail
            v-if="openDetail"
            :row="openDetail"
            :detail="store.detail"
            :loading="store.loading"
            @close="openDetail = null; store.clear()"
        />
    </div>
</template>
