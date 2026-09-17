<script setup>
import { computed, onMounted, ref } from 'vue';
import { useTimesheetStore } from '../stores/timesheets';
import { severityTone, dateTime, timeOf } from '../lib/format';

const store = useTimesheetStore();

const tab = ref('flags');
const severity = ref('');
const unreviewedOnly = ref(true);
const noteFor = ref(null);
const note = ref('');

onMounted(async () => {
    await Promise.all([store.loadAnomalies({ unreviewed_only: 1 }), store.loadEvents()]);
});

async function reload() {
    /*
     * 1 / 0 rather than true / false.
     *
     * The server now accepts both (see NormaliseQueryBooleans), but sending the numeric
     * form keeps this working against an older backend and makes it obvious in the network
     * tab that the flag is being sent at all.
     */
    await store.loadAnomalies({
        severity: severity.value || undefined,
        unreviewed_only: unreviewedOnly.value ? 1 : 0,
    });
}

async function loadTrail() {
    await store.loadEvents();
}

async function review(anomaly) {
    const result = await store.reviewAnomaly(anomaly.id, note.value || null);

    if (result.ok) {
        noteFor.value = null;
        note.value = '';
        await reload();
    }
}

const flags = computed(() => store.anomalies ?? []);
const events = computed(() => store.events?.events ?? []);

/**
 * Oversight records are grouped apart from faults.
 *
 * A manager changing a rate or correcting an entry is not a fault — it is a thing the
 * owner wants to know about because it carries no second signature. Mixing them in with
 * buddy-punching flags would train people to skim the list.
 */
const oversight = computed(() => flags.value.filter((a) => a.is_oversight_record));
const faults = computed(() => flags.value.filter((a) => !a.is_oversight_record));
</script>

<template>
    <div>
        <div class="mb-5">
            <h1 class="text-lg font-semibold text-stone-900">Anomalies</h1>
            <p class="text-sm text-stone-500">
                Anything odd, and the full punch trail behind it — including the attempts that
                failed.
            </p>
        </div>

        <div class="mb-4 flex gap-1 border-b border-stone-200">
            <button
                type="button"
                class="px-4 py-2 text-sm"
                :class="tab === 'flags' ? 'border-b-2 border-amber-900 font-medium text-amber-900' : 'text-stone-500'"
                @click="tab = 'flags'"
            >
                Review queue
                <span v-if="store.unreviewedAnomalies > 0" class="ml-1 rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-800">
                    {{ store.unreviewedAnomalies }}
                </span>
            </button>

            <button
                type="button"
                class="px-4 py-2 text-sm"
                :class="tab === 'trail' ? 'border-b-2 border-amber-900 font-medium text-amber-900' : 'text-stone-500'"
                @click="tab = 'trail'; loadTrail()"
            >
                Punch trail
            </button>
        </div>

        <p v-if="store.error" class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ store.error }}
        </p>

        <!-- Review queue -->
        <template v-if="tab === 'flags'">
            <div class="mb-4 flex flex-wrap items-center gap-3">
                <select
                    v-model="severity"
                    class="rounded-lg border border-stone-300 px-3 py-2 text-sm"
                    @change="reload"
                >
                    <option value="">All severities</option>
                    <option value="high">Needs attention</option>
                    <option value="warn">Worth checking</option>
                    <option value="info">For information</option>
                </select>

                <label class="flex items-center gap-2 text-sm text-stone-700">
                    <input v-model="unreviewedOnly" type="checkbox" @change="reload" />
                    Unreviewed only
                </label>
            </div>

            <ul class="space-y-3">
                <li
                    v-for="a in flags"
                    :key="a.id"
                    class="rounded-xl border border-stone-200 bg-white p-4"
                    :class="{ 'opacity-60': a.is_reviewed }"
                >
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="rounded-full px-2 py-0.5 text-xs" :class="severityTone[a.severity]">
                                    {{ a.severity_label }}
                                </span>
                                <p class="text-sm font-medium text-stone-900">{{ a.type_label }}</p>
                            </div>

                            <p class="mt-1 text-xs text-stone-500">
                                <span v-if="a.employee">{{ a.employee.name }} · </span>
                                <span v-if="a.outlet">{{ a.outlet.name }} · </span>
                                <span v-if="a.started_at">{{ timeOf(a.started_at) }}</span>
                            </p>

                            <p v-if="a.detail" class="mt-1 text-xs text-stone-600">{{ a.detail }}</p>

                            <p v-if="a.is_reviewed" class="mt-1 text-xs text-green-700">
                                Reviewed {{ dateTime(a.reviewed_at) }}
                                <span v-if="a.review_note">— {{ a.review_note }}</span>
                            </p>
                        </div>

                        <button
                            v-if="!a.is_reviewed"
                            type="button"
                            class="rounded-lg border border-stone-300 px-3 py-1.5 text-xs text-stone-700"
                            @click="noteFor = a; note = ''"
                        >
                            Mark reviewed
                        </button>
                    </div>
                </li>

                <li
                    v-if="flags.length === 0"
                    class="rounded-xl border border-stone-200 bg-white px-4 py-8 text-center text-sm text-stone-500"
                >
                    Nothing needs checking.
                </li>
            </ul>
        </template>

        <!-- Punch trail -->
        <template v-else>
            <div class="overflow-hidden rounded-xl border border-stone-200 bg-white">
                <table class="w-full text-sm">
                    <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-stone-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">When</th>
                            <th class="px-4 py-2 font-medium">Event</th>
                            <th class="px-4 py-2 font-medium">Employee</th>
                            <th class="px-4 py-2 font-medium">Outlet</th>
                            <th class="px-4 py-2 font-medium">From</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-stone-100">
                        <tr
                            v-for="event in events"
                            :key="event.id"
                            :class="event.is_failure ? 'bg-red-50' : ''"
                        >
                            <td class="px-4 py-2 text-xs text-stone-600">{{ dateTime(event.occurred_at) }}</td>
                            <td class="px-4 py-2">
                                <span
                                    class="text-xs"
                                    :class="event.is_failure ? 'font-medium text-red-800' : 'text-stone-700'"
                                >
                                    {{ event.event_label }}
                                </span>
                            </td>
                            <!--
                                Blank for a failed PIN, and that blank is the point: it means no
                                PIN matched anyone. A run of these against one code is the
                                signature of someone working through PINs.
                            -->
                            <td class="px-4 py-2 text-xs text-stone-600">
                                {{ event.employee?.name ?? '— no match —' }}
                            </td>
                            <td class="px-4 py-2 text-xs text-stone-600">{{ event.outlet?.name ?? '—' }}</td>
                            <td class="px-4 py-2 text-xs text-stone-400">{{ event.ip_address ?? '—' }}</td>
                        </tr>

                        <tr v-if="events.length === 0">
                            <td colspan="5" class="px-4 py-6 text-center text-sm text-stone-500">
                                No punch activity recorded.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="mt-3 text-xs text-stone-400">
                Attempts that failed are shown too. Without them, "I clocked in and it says I
                didn't" cannot be answered.
            </p>
        </template>

        <!-- Review note sheet -->
        <div v-if="noteFor" class="fixed inset-0 z-40 flex items-end justify-center bg-stone-900/40 p-4 sm:items-center">
            <div class="w-full max-w-md rounded-2xl bg-white p-5">
                <h2 class="text-base font-semibold text-stone-900">Mark reviewed</h2>
                <p class="mt-1 text-sm text-stone-500">{{ noteFor.type_label }}</p>

                <input
                    v-model="note"
                    type="text"
                    placeholder="What you checked, if worth recording"
                    class="mt-3 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
                />

                <div class="mt-4 flex gap-2">
                    <button
                        type="button"
                        class="flex-1 rounded-lg bg-amber-900 py-2.5 text-sm font-medium text-white disabled:opacity-50"
                        :disabled="store.submitting"
                        @click="review(noteFor)"
                    >
                        Mark reviewed
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border border-stone-300 px-4 py-2.5 text-sm text-stone-700"
                        @click="noteFor = null"
                    >
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
