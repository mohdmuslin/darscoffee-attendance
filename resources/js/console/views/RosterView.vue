<script setup>
import { computed, onMounted, ref } from 'vue';
import { useShiftStore, addDays, startOfWeek } from '../stores/shifts';
import { useOutletStore } from '../stores/outlets';
import { useEmployeeStore } from '../stores/employees';
import { hm } from '../lib/format';
import ShiftForm from '../components/ShiftForm.vue';

const shifts = useShiftStore();
const outlets = useOutletStore();
const employees = useEmployeeStore();

const weekStart = ref(startOfWeek(todayIso()));
const showCancelled = ref(false);
const editing = ref(null);
const cancelling = ref(null);
const cancelReason = ref('');

function todayIso() {
    const now = new Date();
    const pad = (n) => String(n).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

/** The range shown: Monday to Sunday, inclusive, which is how a roster is read. */
const range = computed(() => ({
    from: weekStart.value,
    to: addDays(weekStart.value, 6),
}));

onMounted(async () => {
    if (outlets.outlets.length === 0) {
        await outlets.load();
    }

    if (employees.employees.length === 0) {
        // The store reads its own filters rather than taking arguments, so inactive staff
        // are excluded by leaving that filter off — a deactivated employee cannot be
        // rostered anyway.
        employees.filters.include_inactive = false;
        await employees.load();
    }

    await load();
});

async function load() {
    shifts.includeCancelled = showCancelled.value;
    await shifts.loadRange(range.value.from, range.value.to);
}

function moveWeek(weeks) {
    weekStart.value = addDays(weekStart.value, weeks * 7);
    load();
}

function goToday() {
    weekStart.value = startOfWeek(todayIso());
    load();
}

const weekLabel = computed(() => {
    const from = formatDay(range.value.from);
    const to = formatDay(range.value.to);

    return `${from} – ${to}`;
});

function formatDay(isoDate) {
    const [year, month, day] = isoDate.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString([], {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });
}

function isToday(isoDate) {
    return isoDate === todayIso();
}

/** New shifts default to the first visible outlet and a normal trading day. */
function blankShift(date) {
    return {
        employee_id: '',
        outlet_id: shifts.outletId || outlets.outlets[0]?.id || '',
        starts_at: `${date}T09:00`,
        ends_at: `${date}T17:00`,
        position: '',
        note: '',
        allow_overlap: false,
    };
}

function addShift(date) {
    editing.value = { id: null, ...blankShift(date) };
}

function editShift(shift) {
    editing.value = {
        id: shift.id,
        employee_id: shift.employee?.id ?? shift.employee_id,
        outlet_id: shift.outlet_id,
        // The local wall-clock strings the server already formatted. Rebuilding them here
        // from the ISO instant would mean re-implementing the outlet timezone in the
        // browser, which is exactly where an eight-hour error creeps in.
        starts_at: shift.starts_local,
        ends_at: shift.ends_local,
        position: shift.position ?? '',
        note: shift.note ?? '',
        allow_overlap: false,
    };
}

async function saveShift(payload) {
    const result = await shifts.save(editing.value.id, payload);

    if (result.ok) {
        editing.value = null;
    }

    return result;
}

async function confirmCancel() {
    const result = await shifts.cancel(cancelling.value.id, cancelReason.value || null);

    if (result.ok) {
        cancelling.value = null;
        cancelReason.value = '';
    }
}

/**
 * The number of shifts planned per employee, for the footer.
 *
 * Shown because the commonest roster mistake is giving one person six days and another
 * one, and that is invisible in a day-by-day list.
 */
const totals = computed(() => shifts.totals);
</script>

<template>
    <div>
        <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold text-stone-900">Roster</h1>
                <p class="text-sm text-stone-500">
                    Who is planned to work. Separate from what was actually clocked.
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    class="rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-700 hover:bg-stone-50"
                    :disabled="shifts.saving || shifts.shiftCount === 0"
                    @click="shifts.copyForward(1)"
                >
                    Copy to next week
                </button>

                <button
                    type="button"
                    class="rounded-lg bg-amber-900 px-4 py-2 text-sm font-medium text-white"
                    @click="addShift(range.from)"
                >
                    Add shift
                </button>
            </div>
        </div>

        <!-- Week navigation -->
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-stone-200 bg-white px-4 py-3">
            <div class="flex items-center gap-2">
                <button type="button" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm" @click="moveWeek(-1)">
                    ← Previous
                </button>
                <button type="button" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm" @click="goToday">
                    This week
                </button>
                <button type="button" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm" @click="moveWeek(1)">
                    Next →
                </button>
            </div>

            <p class="text-sm font-medium text-stone-800">{{ weekLabel }}</p>

            <div class="flex flex-wrap items-center gap-3">
                <select
                    v-model="shifts.outletId"
                    class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm"
                    @change="load"
                >
                    <option value="">All outlets</option>
                    <option v-for="o in outlets.outlets" :key="o.id" :value="o.id">{{ o.name }}</option>
                </select>

                <label class="flex items-center gap-2 text-sm text-stone-700">
                    <input v-model="showCancelled" type="checkbox" @change="load" />
                    Show cancelled
                </label>
            </div>
        </div>

        <p v-if="shifts.notice" class="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800">
            {{ shifts.notice }}
        </p>

        <p v-if="shifts.error" class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ shifts.error }}
        </p>

        <!--
            A day with nobody rostered is called out. This is the single most useful thing
            the roster view can tell a manager, and it is invisible in a list of shifts.
        -->
        <p
            v-if="shifts.daysWithoutCover.length > 0 && !shifts.loading"
            class="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900"
        >
            Nobody is rostered on
            {{ shifts.daysWithoutCover.length }} day(s) this week.
        </p>

        <!-- The week -->
        <div class="space-y-3">
            <div
                v-for="day in shifts.days"
                :key="day.date"
                class="rounded-xl border bg-white"
                :class="isToday(day.date) ? 'border-amber-900' : 'border-stone-200'"
            >
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-stone-100 px-4 py-2.5">
                    <div class="flex items-center gap-3">
                        <p class="text-sm font-medium text-stone-900">{{ formatDay(day.date) }}</p>
                        <span v-if="isToday(day.date)" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-900">
                            today
                        </span>
                        <span v-if="! day.has_cover" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-900">
                            nobody on
                        </span>
                    </div>

                    <div class="flex items-center gap-3">
                        <p class="text-xs text-stone-500">
                            {{ hm(day.planned_seconds) }} planned
                        </p>
                        <button
                            type="button"
                            class="text-xs text-amber-900 underline"
                            @click="addShift(day.date)"
                        >
                            Add
                        </button>
                    </div>
                </div>

                <ul v-if="day.shifts.length > 0" class="divide-y divide-stone-50">
                    <li
                        v-for="shift in day.shifts"
                        :key="shift.id"
                        class="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5"
                        :class="shift.is_cancelled ? 'opacity-50' : ''"
                    >
                        <div class="flex flex-wrap items-center gap-3">
                            <p class="text-sm text-stone-900">{{ shift.employee?.name }}</p>

                            <p class="text-sm text-stone-600">
                                {{ shift.starts_local.slice(11) }}
                                <span class="text-stone-400">→</span>
                                {{ shift.ends_local.slice(11) }}
                            </p>

                            <span
                                v-if="shift.position"
                                class="rounded bg-stone-100 px-1.5 py-0.5 text-xs text-stone-600"
                            >
                                {{ shift.position }}
                            </span>

                            <!--
                                Cancelled shifts are shown only when asked for, and marked,
                                because a called-off shift is what explains a no-show.
                            -->
                            <span v-if="shift.is_cancelled" class="rounded-full bg-stone-200 px-2 py-0.5 text-xs text-stone-600">
                                cancelled
                            </span>
                        </div>

                        <div v-if="!shift.is_cancelled" class="flex gap-2">
                            <button type="button" class="text-xs text-amber-900 underline" @click="editShift(shift)">
                                Edit
                            </button>
                            <button
                                type="button"
                                class="text-xs text-stone-500 underline"
                                @click="cancelling = shift; cancelReason = ''"
                            >
                                Cancel
                            </button>
                        </div>

                        <p v-if="shift.note" class="w-full text-xs text-stone-400">{{ shift.note }}</p>
                    </li>
                </ul>
            </div>

            <p
                v-if="shifts.days.length === 0 && !shifts.loading"
                class="rounded-xl border border-stone-200 bg-white px-4 py-8 text-center text-sm text-stone-500"
            >
                Nothing rostered this week.
            </p>
        </div>

        <!-- Planned totals -->
        <div v-if="totals.length > 0" class="mt-6">
            <h2 class="mb-2 text-sm font-semibold text-stone-900">Planned this week</h2>

            <div class="overflow-hidden rounded-xl border border-stone-200 bg-white">
                <table class="w-full text-sm">
                    <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-stone-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">Employee</th>
                            <th class="px-4 py-2 font-medium">Shifts</th>
                            <th class="px-4 py-2 font-medium">Planned hours</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        <tr v-for="row in totals" :key="row.employee_id">
                            <td class="px-4 py-2">
                                <p class="text-stone-900">{{ row.name }}</p>
                                <p class="text-xs text-stone-400">{{ row.employee_code }}</p>
                            </td>
                            <td class="px-4 py-2 text-stone-700">{{ row.shift_count }}</td>
                            <td class="px-4 py-2 font-medium text-stone-900">{{ hm(row.planned_seconds) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="mt-2 text-xs text-stone-400">
                Planned hours are a plan, not a record. Pay comes from the timesheet, which counts
                only what was actually clocked.
            </p>
        </div>

        <!-- Shift form -->
        <ShiftForm
            v-if="editing"
            :shift="editing"
            :employees="employees.active"
            :outlets="outlets.outlets"
            :saving="shifts.saving"
            :error="shifts.error"
            :field-errors="shifts.fieldErrors"
            @save="saveShift"
            @close="editing = null; shifts.clearMessages()"
        />

        <!-- Cancel confirmation -->
        <div v-if="cancelling" class="fixed inset-0 z-40 flex items-end justify-center bg-stone-900/40 p-4 sm:items-center">
            <div class="w-full max-w-md rounded-2xl bg-white p-5">
                <h2 class="text-base font-semibold text-stone-900">Cancel this shift?</h2>

                <p class="mt-2 text-sm text-stone-600">
                    {{ cancelling.employee?.name }} ·
                    {{ cancelling.starts_local.slice(11) }}–{{ cancelling.ends_local.slice(11) }}
                    on {{ cancelling.local_date }}
                </p>

                <!--
                    The row is kept, not deleted. Saying so matters: a manager who believed the
                    shift vanished would not expect the day to still show as rostered, and the
                    no-show explanation depends on it being there.
                -->
                <p class="mt-2 text-xs text-stone-500">
                    The shift stays on the record as cancelled, so the day can still be explained later.
                </p>

                <input
                    v-model="cancelReason"
                    type="text"
                    placeholder="Reason (optional)"
                    class="mt-3 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
                />

                <div class="mt-4 flex gap-2">
                    <button
                        type="button"
                        class="flex-1 rounded-lg bg-amber-900 py-2.5 text-sm font-medium text-white disabled:opacity-50"
                        :disabled="shifts.saving"
                        @click="confirmCancel"
                    >
                        {{ shifts.saving ? 'Cancelling…' : 'Cancel the shift' }}
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border border-stone-300 px-4 py-2.5 text-sm text-stone-700"
                        @click="cancelling = null"
                    >
                        Keep it
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
