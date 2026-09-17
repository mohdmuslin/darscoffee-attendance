<script setup>
import { computed, onMounted, ref } from 'vue';
import { useTimesheetStore } from '../stores/timesheets';
import { useAuthStore } from '../stores/auth';
import { correctionTone, dateTime } from '../lib/format';

const store = useTimesheetStore();
const auth = useAuthStore();

const status = ref('');
const reviewing = ref(null);
const note = ref('');
const problem = ref(null);

onMounted(() => store.loadCorrections());

const items = computed(() => {
    const all = store.corrections ?? [];

    return status.value === '' ? all : all.filter((c) => c.status === status.value);
});

const pendingCount = computed(() => (store.corrections ?? []).filter((c) => c.is_pending).length);

async function reload() {
    await store.loadCorrections(status.value ? { status: status.value } : {});
}

async function approve(correction) {
    problem.value = null;

    const result = await store.approveCorrection(correction.id, note.value || null);

    if (result.ok) {
        reviewing.value = null;
        note.value = '';
        await reload();
    } else {
        problem.value = store.error;
    }
}

async function reject(correction) {
    problem.value = null;

    const result = await store.rejectCorrection(correction.id, note.value || null);

    if (result.ok) {
        reviewing.value = null;
        note.value = '';
        await reload();
    } else {
        problem.value = store.error;
    }
}

/** A human-readable summary of what a correction changes, without opening the record. */
function describe(correction) {
    if (correction.action === 'create') {
        return 'A punch that was never recorded.';
    }

    const fields = Object.keys(correction.changes ?? {});

    return fields.length > 0 ? fields.join(', ') : 'No fields listed.';
}
</script>

<template>
    <div>
        <div class="mb-5">
            <h1 class="text-lg font-semibold text-stone-900">Corrections</h1>
            <p class="text-sm text-stone-500">
                Every change to recorded time, with what it was before and who asked.
            </p>
        </div>

        <!--
            A manager can raise a correction but not approve one. Saying so plainly avoids
            the manager wondering why the button does nothing.
        -->
        <p
            v-if="!auth.isOwner && pendingCount > 0"
            class="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900"
        >
            {{ pendingCount }} correction(s) are waiting for the owner to approve. You can see
            them here, but only the owner can approve.
        </p>

        <div class="mb-4 flex flex-wrap gap-2">
            <button
                v-for="option in [
                    { value: '', label: 'All' },
                    { value: 'pending', label: 'Waiting' },
                    { value: 'approved', label: 'Approved' },
                    { value: 'rejected', label: 'Rejected' },
                ]"
                :key="option.value"
                type="button"
                class="rounded-lg border px-3 py-1.5 text-xs"
                :class="status === option.value
                    ? 'border-amber-900 bg-amber-900 text-white'
                    : 'border-stone-300 text-stone-700 hover:bg-stone-50'"
                @click="status = option.value; reload()"
            >
                {{ option.label }}
            </button>
        </div>

        <p v-if="store.error" class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ store.error }}
        </p>

        <ul class="space-y-3">
            <li
                v-for="c in items"
                :key="c.id"
                class="rounded-xl border border-stone-200 bg-white p-4"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <p class="text-sm font-medium text-stone-900">
                            {{ c.employee?.name }}
                            <span class="font-normal text-stone-400">{{ c.employee?.employee_code }}</span>
                        </p>
                        <p class="text-xs text-stone-500">
                            {{ c.outlet?.name }} · {{ c.action_label }} · {{ describe(c) }}
                        </p>
                    </div>

                    <span class="rounded-full px-2 py-0.5 text-xs" :class="correctionTone[c.status]">
                        {{ c.status_label }}
                    </span>
                </div>

                <p class="mt-2 text-sm text-stone-800">{{ c.reason }}</p>

                <!--
                    The before/after comparison IS the value of this record. Showing only the
                    new values would make it a log of what happened rather than an explanation
                    of what changed.
                -->
                <div v-if="c.action === 'update'" class="mt-3 grid gap-2 sm:grid-cols-2">
                    <div class="rounded-lg bg-stone-50 px-3 py-2">
                        <p class="text-xs uppercase tracking-wide text-stone-400">Was</p>
                        <ul class="mt-1 space-y-0.5 text-xs text-stone-600">
                            <li v-for="(value, field) in c.original_values ?? {}" :key="field">
                                {{ field }}: {{ value ?? '—' }}
                            </li>
                        </ul>
                    </div>
                    <div class="rounded-lg bg-blue-50 px-3 py-2">
                        <p class="text-xs uppercase tracking-wide text-blue-500">Becomes</p>
                        <ul class="mt-1 space-y-0.5 text-xs text-blue-900">
                            <li v-for="(value, field) in c.changes ?? {}" :key="field">
                                {{ field }}: {{ value ?? '—' }}
                            </li>
                        </ul>
                    </div>
                </div>

                <p class="mt-2 text-xs text-stone-400">
                    Asked by {{ c.requester?.name }} · {{ dateTime(c.requested_at) }}
                    <span v-if="c.reviewer">· reviewed by {{ c.reviewer.name }}</span>
                </p>

                <p v-if="c.review_note" class="mt-1 text-xs text-stone-500">
                    Note: {{ c.review_note }}
                </p>

                <div v-if="c.is_pending" class="mt-3 flex flex-wrap gap-2">
                    <button
                        v-if="auth.isOwner"
                        type="button"
                        class="rounded-lg bg-amber-900 px-3 py-1.5 text-xs font-medium text-white"
                        @click="reviewing = c; note = ''; problem = null"
                    >
                        Review
                    </button>
                    <p v-else class="text-xs text-stone-500">
                        Waiting for the owner.
                    </p>
                </div>
            </li>

            <li
                v-if="items.length === 0"
                class="rounded-xl border border-stone-200 bg-white px-4 py-8 text-center text-sm text-stone-500"
            >
                No corrections recorded.
            </li>
        </ul>

        <!-- Review sheet -->
        <div v-if="reviewing" class="fixed inset-0 z-40 flex items-end justify-center bg-stone-900/40 p-4 sm:items-center">
            <div class="w-full max-w-lg rounded-2xl bg-white p-5">
                <h2 class="text-base font-semibold text-stone-900">Review this correction</h2>

                <p class="mt-2 text-sm text-stone-700">{{ reviewing.reason }}</p>

                <div class="mt-4">
                    <label class="mb-1 block text-xs text-stone-500" for="note">Note (optional)</label>
                    <input
                        id="note"
                        v-model="note"
                        type="text"
                        class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
                    />
                </div>

                <p v-if="problem" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
                    {{ problem }}
                </p>

                <div class="mt-4 flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="flex-1 rounded-lg bg-amber-900 py-2.5 text-sm font-medium text-white disabled:opacity-50"
                        :disabled="store.submitting"
                        @click="approve(reviewing)"
                    >
                        Approve and apply
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border border-red-300 px-4 py-2.5 text-sm font-medium text-red-700 disabled:opacity-50"
                        :disabled="store.submitting"
                        @click="reject(reviewing)"
                    >
                        Reject
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border border-stone-300 px-4 py-2.5 text-sm text-stone-700"
                        @click="reviewing = null"
                    >
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
