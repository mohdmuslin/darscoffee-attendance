<script setup>
import { computed } from 'vue';
import { hm, businessDate, timeOf } from '../lib/format';

const props = defineProps({
    row: { type: Object, required: true },
    detail: { type: Object, default: null },
    loading: { type: Boolean, default: false },
});

defineEmits(['close']);

/**
 * How each row reads, and how loudly.
 *
 * `not_started` and `in_progress` are deliberately calm: the day has not finished, so there is
 * nothing to act on. Colouring them would put a warning on every shift until it ended, and a
 * report that flags everything is one nobody reads.
 */
const status = {
    met: { label: 'To plan', tone: 'bg-green-100 text-green-800' },
    short: { label: 'Worked short', tone: 'bg-amber-100 text-amber-900' },
    over: { label: 'Worked over', tone: 'bg-amber-100 text-amber-900' },
    no_show: { label: 'No show', tone: 'bg-red-100 text-red-800' },
    cancelled: { label: 'Cancelled', tone: 'bg-stone-200 text-stone-600' },
    in_progress: { label: 'On shift now', tone: 'bg-blue-100 text-blue-800' },
    not_started: { label: 'Still to come', tone: 'bg-stone-100 text-stone-600' },
    unplanned: { label: 'Not rostered', tone: 'bg-amber-100 text-amber-900' },
};

function badge(row) {
    return status[row.status] ?? { label: row.status, tone: 'bg-stone-100 text-stone-600' };
}

/** Rows grouped by day, so the report reads as a calendar rather than a flat list. */
const byDate = computed(() => {
    const rows = props.detail?.rows ?? [];
    const groups = [];

    for (const row of rows) {
        const last = groups[groups.length - 1];

        if (last && last.date === row.date) {
            last.rows.push(row);
        } else {
            groups.push({ date: row.date, rows: [row] });
        }
    }

    return groups;
});

function varianceTone(seconds) {
    if (seconds > 0) {
        return 'text-amber-900';
    }

    if (seconds < 0) {
        return 'text-blue-800';
    }

    return 'text-stone-400';
}

/** A variance figure is only meaningful once the shift's window has passed. */
function isFinished(row) {
    return row.type === 'shift' && ! ['in_progress', 'not_started', 'cancelled'].includes(row.status);
}
</script>

<template>
    <div class="fixed inset-0 z-40 flex items-end justify-center bg-stone-900/40 p-4 sm:items-center">
        <div class="flex max-h-[90vh] w-full max-w-3xl flex-col rounded-2xl bg-white">
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-stone-100 p-5">
                <div>
                    <h2 class="text-base font-semibold text-stone-900">{{ row.name }}</h2>
                    <p class="text-xs text-stone-500">
                        {{ row.employee_code }} ·
                        {{ detail?.from }} → {{ detail?.to }}
                    </p>
                </div>

                <button
                    type="button"
                    class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm text-stone-700"
                    @click="$emit('close')"
                >
                    Close
                </button>
            </div>

            <div v-if="detail" class="grid grid-cols-2 gap-3 border-b border-stone-100 p-5 sm:grid-cols-4">
                <div>
                    <p class="text-xs text-stone-500">Planned</p>
                    <p class="text-lg font-semibold text-stone-900">{{ detail.planned_label }}</p>
                </div>
                <div>
                    <p class="text-xs text-stone-500">Worked</p>
                    <p class="text-lg font-semibold text-stone-900">{{ detail.worked_label }}</p>
                </div>
                <div>
                    <p class="text-xs text-stone-500">Variance</p>
                    <p class="text-lg font-semibold" :class="varianceTone(detail.variance_seconds)">
                        {{ detail.variance_seconds === 0 ? '—' : hm(detail.variance_seconds) }}
                    </p>
                </div>
                <div>
                    <p class="text-xs text-stone-500">Unplanned</p>
                    <p class="text-lg font-semibold" :class="detail.adhoc_seconds > 0 ? 'text-amber-900' : 'text-stone-900'">
                        {{ detail.adhoc_seconds > 0 ? detail.adhoc_label : '—' }}
                    </p>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto p-5">
                <p v-if="loading" class="text-sm text-stone-500">Loading…</p>

                <div v-else-if="byDate.length === 0" class="text-sm text-stone-500">
                    Nothing rostered or worked in this period.
                </div>

                <div v-else class="space-y-4">
                    <div v-for="day in byDate" :key="day.date">
                        <p class="mb-1.5 text-sm font-medium text-stone-900">
                            {{ businessDate(day.date) }}
                        </p>

                        <ul class="space-y-1.5">
                            <li
                                v-for="(entry, index) in day.rows"
                                :key="`${day.date}-${index}`"
                                class="rounded-lg border border-stone-100 px-3 py-2"
                                :class="entry.is_cancelled ? 'opacity-60' : ''"
                            >
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="rounded-full px-2 py-0.5 text-xs" :class="badge(entry).tone">
                                            {{ badge(entry).label }}
                                        </span>

                                        <span v-if="entry.type === 'shift'" class="text-sm text-stone-700">
                                            {{ timeOf(entry.planned_start) }} → {{ timeOf(entry.planned_end) }}
                                        </span>

                                        <span v-else class="text-sm text-stone-700">
                                            Worked, not rostered
                                        </span>

                                        <span v-if="entry.position" class="text-xs text-stone-400">
                                            {{ entry.position }}
                                        </span>
                                    </div>

                                    <div class="flex items-center gap-3 text-xs">
                                        <!--
                                            A cancelled shift has no plan to report — it was
                                            withdrawn — so the figure is omitted rather than shown
                                            as "planned 0m", which would read as a real commitment.
                                        -->
                                        <span v-if="entry.type === 'shift' && ! entry.is_cancelled" class="text-stone-500">
                                            planned {{ entry.planned_label }}
                                        </span>
                                        <span class="text-stone-700">worked {{ entry.worked_label }}</span>
                                        <!--
                                            A variance is only shown for a shift that has FINISHED.
                                            Half way through a shift the hours are always less than
                                            planned, so printing "-12h 0m" against a shift still to
                                            come is a number nobody can act on.
                                        -->
                                        <span
                                            v-if="isFinished(entry) && entry.variance_seconds !== 0"
                                            class="font-medium"
                                            :class="varianceTone(entry.variance_seconds)"
                                        >
                                            {{ hm(entry.variance_seconds) }}
                                        </span>
                                    </div>
                                </div>

                                <p v-if="entry.is_corrected" class="mt-1 text-xs text-blue-800">
                                    Recorded by a correction, not a punch.
                                </p>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <p class="border-t border-stone-100 px-5 py-3 text-xs text-stone-400">
                A cancelled shift is kept on the record but never absorbs a punch: the plan was
                withdrawn, so any work that day shows as unplanned.
            </p>
        </div>
    </div>
</template>
