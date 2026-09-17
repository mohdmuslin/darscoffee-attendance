<script setup>
import { computed, onMounted, ref } from 'vue';
import { usePayStore } from '../stores/pay';
import { useOutletStore } from '../stores/outlets';
import { hm } from '../lib/format';
import PayPeriodPanel from '../components/PayPeriodPanel.vue';
import RateForm from '../components/RateForm.vue';

const store = usePayStore();
const outlets = useOutletStore();

const period = ref(store.currentMonth());
const editing = ref(null);
const showPeriods = ref(false);

onMounted(async () => {
    if (outlets.outlets.length === 0) {
        await outlets.load();
    }

    await Promise.all([store.loadSummary(period.value.from, period.value.to), store.loadRates()]);
});

async function reload() {
    await Promise.all([store.loadSummary(period.value.from, period.value.to), store.loadRates()]);
}

function setThisMonth() {
    period.value = store.currentMonth();
    reload();
}

function setPreset(days) {
    period.value = lastNDays(days);
    reload();
}

function lastNDays(count) {
    const to = new Date();
    const from = new Date();

    from.setDate(from.getDate() - (count - 1));

    const pad = (n) => String(n).padStart(2, '0');
    const iso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

    return { from: iso(from), to: iso(to) };
}

/** Money, always two decimals — a figure on a payslip with one decimal looks like a bug. */
function money(value) {
    if (value === null || value === undefined) {
        return '—';
    }

    return Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

async function saveRate(payload) {
    const result = await store.saveRate(editing.value.employee_id, payload);

    if (result.ok) {
        editing.value = null;
    }

    return result;
}

const total = computed(() => store.summary?.totals?.total_amount ?? null);
</script>

<template>
    <div>
        <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold text-stone-900">Pay</h1>
                <p class="text-sm text-stone-500">
                    What the recorded hours are worth. Not a payroll run — tax and statutory
                    deductions are out of scope, so this is a figure to hand over.
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    class="rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-700 hover:bg-stone-50"
                    @click="showPeriods = true"
                >
                    Pay periods
                </button>

                <button
                    type="button"
                    class="rounded-lg bg-amber-900 px-4 py-2 text-sm font-medium text-white"
                    @click="store.downloadCsv()"
                >
                    Export CSV
                </button>
            </div>
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

                <button
                    type="button"
                    class="rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-700 hover:bg-stone-50"
                    @click="setThisMonth"
                >
                    This month
                </button>
            </div>
        </div>

        <p v-if="store.notice" class="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800">
            {{ store.notice }}
        </p>

        <p v-if="store.error" class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ store.error }}
        </p>

        <!--
            An employee with hours but no rate is called out before the totals, because a total
            that silently excludes somebody is worse than a visible gap — someone would pay it.
        -->
        <div v-if="store.unpriced.length > 0" class="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <strong>{{ store.unpriced.length }} employee(s) have no pay rate set.</strong>
            Their hours are recorded but not priced, so they are excluded from the total below.
        </div>

        <!--
            A locked period is shown as a commitment, and a drifted one is stated plainly: the
            figure that was approved no longer matches what the data says, so somebody has to
            decide which one is owed.
        -->
        <div
            v-if="store.periodSummary"
            class="mb-4 rounded-lg px-4 py-3 text-sm"
            :class="store.periodSummary.is_locked ? 'bg-blue-50 text-blue-900' : 'bg-stone-50 text-stone-700'"
        >
            <span v-if="store.periodSummary.is_locked">
                <strong>{{ store.periodSummary.name }}</strong> is locked
                <span v-if="store.periodSummary.locked_by"> by {{ store.periodSummary.locked_by }}</span>.
                The figures below are computed live; the committed record is in Pay periods.
            </span>
            <span v-else>
                <strong>{{ store.periodSummary.name }}</strong> is open. Nothing is committed yet.
            </span>
        </div>

        <!-- Totals -->
        <div v-if="store.summary" class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-xl border border-stone-200 bg-white p-4">
                <p class="text-xs text-stone-500">Total</p>
                <p class="mt-1 text-2xl font-semibold text-stone-900">
                    {{ store.summary.currency }} {{ money(total) }}
                </p>
                <p class="text-xs text-stone-400">{{ store.headline }}</p>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white p-4">
                <p class="text-xs text-stone-500">Ordinary</p>
                <p class="mt-1 text-2xl font-semibold text-stone-900">
                    {{ money(store.summary.totals.ordinary_amount) }}
                </p>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white p-4">
                <p class="text-xs text-stone-500">Overtime</p>
                <p class="mt-1 text-2xl font-semibold text-stone-900">
                    {{ money(store.summary.totals.overtime_amount) }}
                </p>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white p-4">
                <p class="text-xs text-stone-500">Hours</p>
                <p class="mt-1 text-2xl font-semibold text-stone-900">
                    {{ hm(store.summary.totals.worked_seconds) }}
                </p>
            </div>
        </div>

        <!-- Per-employee -->
        <div class="overflow-hidden rounded-xl border border-stone-200 bg-white">
            <table class="w-full text-sm">
                <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-stone-500">
                    <tr>
                        <th class="px-4 py-2 font-medium">Employee</th>
                        <th class="px-4 py-2 font-medium">Basis</th>
                        <th class="px-4 py-2 font-medium">Rate</th>
                        <th class="px-4 py-2 font-medium">Hours</th>
                        <th class="px-4 py-2 font-medium">OT</th>
                        <th class="px-4 py-2 font-medium">Amount</th>
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
                            <p v-for="(warning, i) in row.warnings" :key="i" class="mt-0.5 text-xs text-amber-800">
                                {{ warning }}
                            </p>
                        </td>
                        <td class="px-4 py-2 text-stone-600">{{ row.basis ?? '—' }}</td>
                        <td class="px-4 py-2 text-stone-700">
                            {{ row.rate !== null ? money(row.rate) : '—' }}
                        </td>
                        <td class="px-4 py-2 text-stone-700">{{ row.worked_label }}</td>
                        <td class="px-4 py-2 text-stone-700">
                            {{ row.overtime_seconds > 0 ? row.overtime_label : '—' }}
                        </td>
                        <td class="px-4 py-2 font-medium" :class="row.total_amount === null ? 'text-amber-800' : 'text-stone-900'">
                            {{ row.total_amount === null ? 'no rate' : money(row.total_amount) }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            <button
                                type="button"
                                class="text-xs text-amber-900 underline"
                                @click="editing = row"
                            >
                                Set rate
                            </button>
                        </td>
                    </tr>

                    <tr v-if="(store.summary?.employees ?? []).length === 0 && !store.loading">
                        <td colspan="7" class="px-4 py-6 text-center text-sm text-stone-500">
                            No hours recorded in this period.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="mt-3 text-xs text-stone-400">
            Overtime is measured on hours <strong>worked</strong> per day, so breaks never create
            it. A monthly rate pays exactly the monthly figure for a full month. Amounts are
            rounded once per employee, and the total is the sum of those amounts — so it matches
            the individual figures.
        </p>

        <RateForm
            v-if="editing"
            :row="editing"
            :saving="store.saving"
            :error="store.error"
            :field-errors="store.fieldErrors"
            @save="saveRate"
            @close="editing = null; store.clearMessages()"
        />

        <PayPeriodPanel
            v-if="showPeriods"
            @close="showPeriods = false"
        />
    </div>
</template>
