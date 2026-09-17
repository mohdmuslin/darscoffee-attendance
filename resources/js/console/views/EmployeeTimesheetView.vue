<script setup>
import { computed, onMounted, ref } from 'vue';
import { useTimesheetStore } from '../stores/timesheets';
import { dateTime, dayStatusTone, businessDate, hm, timeOf, toIsoDate } from '../lib/format';

const props = defineProps({
    id: { type: [String, Number], required: true },
});

const store = useTimesheetStore();

/* The entry being corrected, or null. Drives the correction sheet. */
const editing = ref(null);
const changes = ref({});
const reason = ref('');
const problem = ref(null);

/* Recording a punch for a day with nothing to amend. */
const addingMissing = ref(false);
const missing = ref({ started_at: '', ended_at: '', note: '' });

onMounted(() => store.loadEmployee(props.id));

/**
 * The day rows, excluding empty ones.
 *
 * A period can include days with neither a punch nor a roster, and those are noise on a
 * timesheet. Days that are empty BECAUSE a shift was missed are kept — those are the ones
 * worth seeing.
 */
const days = computed(() => (store.timesheet?.days ?? []).filter(
    (day) => day.entries.length > 0 || day.status === 'no_show',
));

function beginCorrection(entry) {
    editing.value = entry;

    // Pre-filled with the current values so the manager edits rather than retypes, and so
    // the diff the server records is genuinely the change they intended.
    changes.value = { started_at: toLocalInput(entry.started_at), ended_at: entry.ended_at ? toLocalInput(entry.ended_at) : '' };
    reason.value = '';
    problem.value = null;
}

function cancelCorrection() {
    editing.value = null;
    changes.value = {};
    reason.value = '';
    problem.value = null;
}

/**
 * ISO to a `datetime-local` value.
 *
 * The input works in the BROWSER's local time, so the ISO string has to be shifted into
 * that zone or the manager sees a time they do not recognise and "corrects" it to itself.
 */
function toLocalInput(iso) {
    const date = new Date(iso);
    const pad = (n) => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

async function submitCorrection() {
    problem.value = null;

    if (editing.value === null) {
        return;
    }

    if (reason.value.trim().length < 5) {
        problem.value = 'Give a reason someone reading this in six months would understand.';

        return;
    }

    /*
     * Only what actually changed is sent, so the audit record reads as a diff rather than
     * as a full restatement. Sending everything would make every correction look as though
     * every field had been touched.
     */
    const payload = {};

    if (changes.value.started_at && changes.value.started_at !== toLocalInput(editing.value.started_at)) {
        payload.started_at = new Date(changes.value.started_at).toISOString();
    }

    const originalEnd = editing.value.ended_at ? toLocalInput(editing.value.ended_at) : '';

    if (changes.value.ended_at !== originalEnd) {
        payload.ended_at = changes.value.ended_at === ''
            ? null
            : new Date(changes.value.ended_at).toISOString();
    }

    if (Object.keys(payload).length === 0) {
        problem.value = 'Nothing has been changed.';

        return;
    }

    const result = await store.requestCorrection(editing.value.id, payload, reason.value.trim());

    if (result.ok) {
        cancelCorrection();
        await store.loadEmployee(props.id);
    } else {
        problem.value = store.error;
    }
}

async function submitMissing() {
    problem.value = null;

    if (! missing.value.started_at || ! missing.value.ended_at) {
        problem.value = 'Both a start and an end are needed.';

        return;
    }

    const result = await store.requestMissingPunch({
        // employee_id and outlet_id are on the timesheet payload, so the sheet does not
        // have to ask for values it already knows.
        employee_id: Number(props.id),
        outlet_id: store.timesheet.days.flatMap((day) => day.entries).at(0)?.outlet_id ?? null,
        started_at: new Date(missing.value.started_at).toISOString(),
        ended_at: new Date(missing.value.ended_at).toISOString(),
        note: missing.value.note || null,
        reason: reason.value.trim(),
    });

    if (result.ok) {
        addingMissing.value = false;
        missing.value = { started_at: '', ended_at: '', note: '' };
        reason.value = '';
        await store.loadEmployee(props.id);
    } else {
        problem.value = store.error;
    }
}

/** Today, as a `datetime-local` default for the missing-punch form. */
function todayInput(hour) {
    const date = new Date();

    date.setHours(hour, 0, 0, 0);

    return toLocalInput(date.toISOString());
}

function startMissing() {
    addingMissing.value = true;
    reason.value = '';
    problem.value = null;
    missing.value = {
        started_at: todayInput(9),
        ended_at: todayInput(17),
        note: '',
    };
}

/* Default the outlet for a missing punch from whatever this employee last worked at. */
const lastOutletId = computed(() => store.timesheet?.days
    .flatMap((day) => day.entries)
    .at(-1)?.outlet_id ?? null);

defineExpose({ lastOutletId });
</script>

<template>
    <div>
        <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="text-xs text-stone-500">
                    <RouterLink :to="{ name: 'timesheets' }" class="underline">Timesheets</RouterLink>
                    <span class="mx-1">/</span>
                    {{ store.timesheet?.employee.name ?? '…' }}
                </p>
                <h1 class="text-lg font-semibold text-stone-900">
                    {{ store.timesheet?.employee.name ?? 'Loading…' }}
                </h1>
                <p class="text-xs text-stone-400">
                    {{ store.timesheet?.employee.employee_code }}
                    <span v-if="store.timesheet?.employee.pay_basis">
                        · {{ store.timesheet.employee.pay_basis }}
                    </span>
                </p>
            </div>

            <button
                type="button"
                class="rounded-lg border border-amber-900 px-4 py-2 text-sm font-medium text-amber-900"
                @click="startMissing"
            >
                Add a missing punch
            </button>
        </div>

        <p v-if="store.error && !editing" class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ store.error }}
        </p>

        <!-- Period totals -->
        <div v-if="store.timesheet" class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-xl border border-stone-200 bg-white p-4">
                <p class="text-xs text-stone-500">Worked</p>
                <p class="mt-1 text-2xl font-semibold text-stone-900">
                    {{ store.timesheet.worked_label }}
                </p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white p-4">
                <p class="text-xs text-stone-500">Breaks</p>
                <p class="mt-1 text-2xl font-semibold text-stone-900">
                    {{ store.timesheet.break_label }}
                </p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white p-4">
                <p class="text-xs text-stone-500">Overtime</p>
                <p class="mt-1 text-2xl font-semibold text-stone-900">
                    {{ store.timesheet.overtime_label }}
                </p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white p-4">
                <p class="text-xs text-stone-500">Period</p>
                <p class="mt-1 text-sm font-medium text-stone-900">
                    {{ store.timesheet.from }} → {{ store.timesheet.to }}
                </p>
            </div>
        </div>

        <!-- Days -->
        <div class="space-y-3">
            <div
                v-for="day in days"
                :key="day.business_date"
                class="rounded-xl border border-stone-200 bg-white"
            >
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-stone-100 px-4 py-3">
                    <div class="flex items-center gap-3">
                        <p class="text-sm font-medium text-stone-900">
                            {{ businessDate(day.business_date) }}
                        </p>
                        <span
                            class="rounded-full px-2 py-0.5 text-xs"
                            :class="dayStatusTone[day.status]"
                        >
                            {{ day.status.replace('_', ' ') }}
                        </span>

                        <!--
                            Lateness and early-out are shown next to the day rather than in a
                            separate report, because the moment a manager is looking at the
                            hours is the moment the question occurs to them.
                        -->
                        <span v-if="day.lateness?.is_late" class="text-xs text-amber-900">
                            late {{ day.lateness.late_by_label }}
                        </span>
                        <span v-if="day.lateness?.left_early_by_label" class="text-xs text-amber-900">
                            left {{ day.lateness.left_early_by_label }} early
                        </span>
                    </div>

                    <div class="text-right">
                        <p class="text-sm font-semibold text-stone-900">{{ day.worked_label }}</p>
                        <p v-if="day.overtime_seconds > 0" class="text-xs text-stone-500">
                            +{{ day.overtime_label }} OT
                        </p>
                    </div>
                </div>

                <table v-if="day.entries.length > 0" class="w-full text-sm">
                    <tbody class="divide-y divide-stone-50">
                        <tr v-for="entry in day.entries" :key="entry.id">
                            <td class="px-4 py-2">
                                <span
                                    class="rounded px-1.5 py-0.5 text-xs"
                                    :class="entry.type === 'work' ? 'bg-green-50 text-green-800' : 'bg-stone-100 text-stone-600'"
                                >
                                    {{ entry.type_label }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-stone-700">
                                {{ timeOf(entry.started_at) }}
                                <span class="text-stone-400">→</span>
                                {{ entry.ended_at ? timeOf(entry.ended_at) : 'open' }}
                            </td>
                            <td class="px-4 py-2 text-stone-700">{{ entry.duration_label }}</td>
                            <td class="px-4 py-2">
                                <!-- A corrected row is marked, because the times shown are no
                                     longer what the employee punched and the manager needs to
                                     know that without opening the trail. -->
                                <span v-if="entry.is_corrected" class="rounded-full bg-blue-100 px-2 py-0.5 text-xs text-blue-800">
                                    corrected
                                </span>
                                <span v-if="entry.status === 'open'" class="rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-800">
                                    open
                                </span>
                            </td>
                            <td class="px-4 py-2 text-right">
                                <button
                                    type="button"
                                    class="text-xs text-amber-900 underline"
                                    @click="beginCorrection(entry)"
                                >
                                    Correct
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p v-else class="px-4 py-3 text-sm text-stone-500">
                    Rostered, but nothing was recorded.
                </p>
            </div>

            <p v-if="days.length === 0 && !store.loading" class="rounded-xl border border-stone-200 bg-white px-4 py-8 text-center text-sm text-stone-500">
                Nothing recorded in this period.
            </p>
        </div>

        <!-- Corrections on this employee -->
        <div v-if="(store.timesheet?.corrections ?? []).length > 0" class="mt-6">
            <h2 class="mb-2 text-sm font-semibold text-stone-900">Corrections</h2>

            <ul class="divide-y divide-stone-100 rounded-xl border border-stone-200 bg-white">
                <li v-for="c in store.timesheet.corrections" :key="c.id" class="px-4 py-3">
                    <p class="text-sm text-stone-800">{{ c.reason }}</p>
                    <p class="mt-1 text-xs text-stone-500">
                        {{ c.status }} · asked by {{ c.requested_by }}
                        <span v-if="c.reviewed_by">· approved by {{ c.reviewed_by }}</span>
                        · {{ dateTime(c.created_at) }}
                    </p>
                </li>
            </ul>
        </div>

        <!--
            The correction sheet. Opens over the timesheet rather than navigating away, so
            the manager can still see the day they are amending while they amend it.
        -->
        <div v-if="editing" class="fixed inset-0 z-40 flex items-end justify-center bg-stone-900/40 p-4 sm:items-center">
            <div class="w-full max-w-lg rounded-2xl bg-white p-5">
                <h2 class="text-base font-semibold text-stone-900">Correct this punch</h2>

                <p class="mt-1 text-xs text-stone-500">
                    The original times are kept. This records what changed, who asked and why.
                </p>

                <div class="mt-4 space-y-3">
                    <div>
                        <label class="mb-1 block text-xs text-stone-500" for="started">Started</label>
                        <input
                            id="started"
                            v-model="changes.started_at"
                            type="datetime-local"
                            class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
                        />
                        <p class="mt-1 text-xs text-stone-400">
                            Was {{ dateTime(editing.started_at) }}
                        </p>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs text-stone-500" for="ended">Ended</label>
                        <input
                            id="ended"
                            v-model="changes.ended_at"
                            type="datetime-local"
                            class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
                        />
                        <p class="mt-1 text-xs text-stone-400">
                            {{ editing.ended_at ? `Was ${dateTime(editing.ended_at)}` : 'Still open' }}
                        </p>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs text-stone-500" for="reason">
                            Reason <span class="text-red-600">*</span>
                        </label>
                        <textarea
                            id="reason"
                            v-model="reason"
                            rows="3"
                            placeholder="For example: forgot to clock out, confirmed with the closing manager."
                            class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
                        ></textarea>
                        <p class="mt-1 text-xs text-stone-400">
                            Required. This is the only explanation on the record.
                        </p>
                    </div>
                </div>

                <p v-if="problem" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
                    {{ problem }}
                </p>

                <div class="mt-4 flex gap-2">
                    <button
                        type="button"
                        class="flex-1 rounded-lg bg-amber-900 py-2.5 text-sm font-medium text-white disabled:opacity-50"
                        :disabled="store.submitting"
                        @click="submitCorrection"
                    >
                        {{ store.submitting ? 'Saving…' : 'Save correction' }}
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border border-stone-300 px-4 py-2.5 text-sm text-stone-700"
                        @click="cancelCorrection"
                    >
                        Cancel
                    </button>
                </div>
            </div>
        </div>

        <!-- Missing punch sheet -->
        <div v-if="addingMissing" class="fixed inset-0 z-40 flex items-end justify-center bg-stone-900/40 p-4 sm:items-center">
            <div class="w-full max-w-lg rounded-2xl bg-white p-5">
                <h2 class="text-base font-semibold text-stone-900">Add a missing punch</h2>

                <p class="mt-1 text-xs text-stone-500">
                    For a shift nobody punched at all. The segment is marked as corrected, so a
                    report can tell it apart from a recorded one.
                </p>

                <div class="mt-4 space-y-3">
                    <div>
                        <label class="mb-1 block text-xs text-stone-500" for="mstart">Started</label>
                        <input id="mstart" v-model="missing.started_at" type="datetime-local" class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-stone-500" for="mend">Ended</label>
                        <input id="mend" v-model="missing.ended_at" type="datetime-local" class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-stone-500" for="mnote">Note</label>
                        <input id="mnote" v-model="missing.note" type="text" placeholder="Optional" class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-stone-500" for="mreason">
                            Reason <span class="text-red-600">*</span>
                        </label>
                        <textarea id="mreason" v-model="reason" rows="3" class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"></textarea>
                    </div>
                </div>

                <p v-if="problem" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
                    {{ problem }}
                </p>

                <div class="mt-4 flex gap-2">
                    <button
                        type="button"
                        class="flex-1 rounded-lg bg-amber-900 py-2.5 text-sm font-medium text-white disabled:opacity-50"
                        :disabled="store.submitting"
                        @click="submitMissing"
                    >
                        {{ store.submitting ? 'Saving…' : 'Record the punch' }}
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border border-stone-300 px-4 py-2.5 text-sm text-stone-700"
                        @click="addingMissing = false"
                    >
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
