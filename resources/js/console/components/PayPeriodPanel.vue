<script setup>
import { computed, onMounted, ref } from 'vue';
import { usePayStore } from '../stores/pay';
import { useAuthStore } from '../stores/auth';
import { dateTime } from '../lib/format';

const emit = defineEmits(['close']);

const store = usePayStore();
const auth = useAuthStore();

const creating = ref(false);
const form = ref({ name: '', starts_on: '', ends_on: '', note: '' });
const problem = ref(null);

onMounted(async () => {
    await store.loadPeriods();

    if (store.suggestion) {
        form.value = { ...store.suggestion, note: '' };
    }
});

function money(value) {
    if (value === null || value === undefined) {
        return '—';
    }

    return Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

async function create() {
    problem.value = null;

    const result = await store.createPeriod({
        name: form.value.name,
        starts_on: form.value.starts_on,
        ends_on: form.value.ends_on,
        note: form.value.note || null,
    });

    if (result.ok) {
        creating.value = false;
    } else {
        problem.value = store.error;
    }
}

async function lock(period) {
    problem.value = null;

    const result = await store.lockPeriod(period.id);

    if (! result.ok) {
        problem.value = store.error;
    }
}

async function inspect(period) {
    problem.value = null;
    await store.loadReconciliation(period.id);
}

const reconciliation = computed(() => store.reconciliation);

/** Plain-English statement of what the reconciliation found. */
const reconciliationMessage = computed(() => {
    const r = reconciliation.value;

    if (r === null) {
        return null;
    }

    if (r.state === 'open') {
        return 'Nothing is committed for this period yet, so nothing can have drifted.';
    }

    if (r.state === 'locked') {
        return 'The figures still match what was approved when this period was locked.';
    }

    if (r.difference?.inputs_changed_only) {
        /*
         * The hours moved but the money did not — which happens when a shift is extended into
         * overtime at an outlet with no overtime rate configured. Worth saying explicitly,
         * because "drifted" beside an empty difference list reads as a bug.
         */
        return 'The recorded hours have changed, but the amount has not — the extra time is '
            + 'overtime and no overtime rate is set, so it is not priced.';
    }

    return 'The figures have changed since this period was locked. The approved amount is what '
        + 'was paid; the current amount is what the data now says.';
});
</script>

<template>
    <div class="fixed inset-0 z-40 flex items-end justify-center bg-stone-900/40 p-4 sm:items-center">
        <div class="flex max-h-[90vh] w-full max-w-3xl flex-col rounded-2xl bg-white">
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-stone-100 p-5">
                <div>
                    <h2 class="text-base font-semibold text-stone-900">Pay periods</h2>
                    <p class="text-xs text-stone-500">
                        Locking a period commits its figures, so a figure already handed out stops
                        changing.
                    </p>
                </div>

                <div class="flex gap-2">
                    <button
                        type="button"
                        class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm text-stone-700"
                        @click="creating = ! creating"
                    >
                        New period
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm text-stone-700"
                        @click="emit('close')"
                    >
                        Close
                    </button>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto p-5">
                <p v-if="problem" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
                    {{ problem }}
                </p>

                <!-- New period -->
                <div v-if="creating" class="mb-5 rounded-xl border border-stone-200 p-4">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div>
                            <label class="mb-1 block text-xs text-stone-500" for="pname">Name</label>
                            <input id="pname" v-model="form.name" type="text" class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-stone-500" for="pstart">Starts</label>
                            <input id="pstart" v-model="form.starts_on" type="date" class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-stone-500" for="pend">Ends</label>
                            <input id="pend" v-model="form.ends_on" type="date" class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm" />
                        </div>
                    </div>

                    <button
                        type="button"
                        class="mt-3 rounded-lg bg-amber-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                        :disabled="store.saving"
                        @click="create"
                    >
                        Create
                    </button>
                </div>

                <!-- Period list -->
                <ul class="space-y-2">
                    <li
                        v-for="period in store.periods"
                        :key="period.id"
                        class="rounded-xl border border-stone-200 p-4"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <p class="text-sm font-medium text-stone-900">{{ period.name }}</p>
                                <p class="text-xs text-stone-500">
                                    {{ period.starts_on }} → {{ period.ends_on }}
                                    ({{ period.day_count }} days)
                                </p>
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs"
                                    :class="period.is_locked ? 'bg-blue-100 text-blue-800' : 'bg-stone-100 text-stone-600'"
                                >
                                    {{ period.is_locked ? 'locked' : 'open' }}
                                </span>

                                <button
                                    type="button"
                                    class="text-xs text-amber-900 underline"
                                    @click="inspect(period)"
                                >
                                    Check
                                </button>

                                <!--
                                    Locking is owner-only: it is the act that makes a figure the
                                    record of what was paid, and a manager committing their own
                                    outlet's payroll is not a power to hand out.
                                -->
                                <button
                                    v-if="! period.is_locked && auth.isOwner"
                                    type="button"
                                    class="rounded-lg border border-amber-900 px-3 py-1.5 text-xs font-medium text-amber-900 disabled:opacity-50"
                                    :disabled="store.saving"
                                    @click="lock(period)"
                                >
                                    Lock
                                </button>
                            </div>
                        </div>

                        <div v-if="period.is_locked" class="mt-2 text-xs text-stone-500">
                            Committed:
                            <strong class="text-stone-800">{{ money(period.committed_total) }}</strong>
                            <span v-if="period.locked_by"> · locked by {{ period.locked_by }}</span>
                            <span v-if="period.locked_at"> · {{ dateTime(period.locked_at) }}</span>
                        </div>

                        <!-- Reconciliation for the inspected period -->
                        <div
                            v-if="reconciliation && reconciliation.period.id === period.id"
                            class="mt-3 rounded-lg px-3 py-2 text-xs"
                            :class="reconciliation.state === 'drifted'
                                ? 'bg-amber-50 text-amber-900'
                                : (reconciliation.state === 'locked' ? 'bg-green-50 text-green-800' : 'bg-stone-50 text-stone-700')"
                        >
                            <p>{{ reconciliationMessage }}</p>

                            <div v-if="reconciliation.state === 'drifted'" class="mt-2 space-y-1">
                                <p>
                                    Approved <strong>{{ money(reconciliation.approved.totals.total_amount) }}</strong>
                                    → now
                                    <strong>{{ money(reconciliation.current.totals.total_amount) }}</strong>
                                    ({{ money(reconciliation.difference.delta_total) }})
                                </p>

                                <ul v-if="reconciliation.difference.employees.length > 0" class="list-inside list-disc">
                                    <li v-for="row in reconciliation.difference.employees" :key="row.employee_id">
                                        {{ row.name }}: {{ money(row.before) }} → {{ money(row.after) }}
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </li>

                    <li
                        v-if="store.periods.length === 0"
                        class="rounded-xl border border-stone-200 px-4 py-6 text-center text-sm text-stone-500"
                    >
                        No pay periods yet.
                    </li>
                </ul>
            </div>

            <p class="border-t border-stone-100 px-5 py-3 text-xs text-stone-400">
                A locked period is not frozen. A genuine missed clock-out still needs correcting, so
                the change is allowed and detected instead — the committed figure stays as the
                record of what was paid.
            </p>
        </div>
    </div>
</template>
