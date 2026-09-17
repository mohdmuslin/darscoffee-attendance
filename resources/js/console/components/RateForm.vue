<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    /** A row from the pay summary, carrying the employee and their current rate. */
    row: { type: Object, required: true },
    saving: { type: Boolean, default: false },
    error: { type: String, default: null },
    fieldErrors: { type: Object, default: null },
});

const emit = defineEmits(['save', 'close']);

const form = ref({
    basis: props.row.basis ?? 'hourly',
    rate: props.row.rate ?? '',
    overtime_rate: props.row.overtime_rate ?? '',
    effective_from: todayIso(),
    note: '',
});

/*
 * Re-copy if the parent swaps in a different employee. Without this, opening a second employee
 * in the same session would show the first one's rate — and a saved rate on the wrong person is
 * a real cost.
 */
watch(() => props.row, (value) => {
    form.value = {
        basis: value.basis ?? 'hourly',
        rate: value.rate ?? '',
        overtime_rate: value.overtime_rate ?? '',
        effective_from: todayIso(),
        note: '',
    };
}, { deep: true });

function todayIso() {
    const now = new Date();
    const pad = (n) => String(n).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

const canSave = computed(() => form.value.rate !== '' && form.value.effective_from !== '');

function fieldError(field) {
    return props.fieldErrors?.[field]?.[0] ?? null;
}

/** What the stored figure MEANS, so a monthly amount is never read as an hourly one. */
const unit = computed(() => ({
    hourly: 'per hour',
    daily: 'per day',
    weekly: 'per week',
    monthly: 'per month',
})[form.value.basis] ?? '');

function submit() {
    if (! canSave.value) {
        return;
    }

    emit('save', {
        basis: form.value.basis,
        // Sent as a string. Sending a float risks the value arriving as 10.000000000000002,
        // and the validation requires at most two decimals.
        rate: String(form.value.rate),
        overtime_rate: form.value.overtime_rate === '' ? null : String(form.value.overtime_rate),
        effective_from: form.value.effective_from,
        note: form.value.note || null,
    });
}
</script>

<template>
    <div class="fixed inset-0 z-40 flex items-end justify-center bg-stone-900/40 p-4 sm:items-center">
        <div class="w-full max-w-lg rounded-2xl bg-white p-5">
            <h2 class="text-base font-semibold text-stone-900">
                Pay rate for {{ row.name }}
            </h2>

            <p class="mt-1 text-xs text-stone-500">
                Rows are kept, not overwritten. Setting a new rate closes the previous one, so
                last month can still be reproduced afterwards.
            </p>

            <div class="mt-4 space-y-3">
                <div>
                    <label class="mb-1 block text-xs text-stone-500" for="basis">Paid by</label>
                    <select
                        id="basis"
                        v-model="form.basis"
                        class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
                    >
                        <option value="hourly">Hour</option>
                        <option value="daily">Day</option>
                        <option value="weekly">Week</option>
                        <option value="monthly">Month</option>
                    </select>
                    <p class="mt-1 text-xs text-stone-400">
                        A monthly figure is not an hourly one: it pays exactly this amount for a
                        full month, and nothing is derived from a nominal day length.
                    </p>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs text-stone-500" for="rate">
                            Rate {{ unit }}
                        </label>
                        <input
                            id="rate"
                            v-model="form.rate"
                            type="number"
                            step="0.01"
                            min="0"
                            class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
                        />
                        <p v-if="fieldError('rate')" class="mt-1 text-xs text-red-600">
                            {{ fieldError('rate') }}
                        </p>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs text-stone-500" for="ot">Overtime per hour</label>
                        <input
                            id="ot"
                            v-model="form.overtime_rate"
                            type="number"
                            step="0.01"
                            min="0"
                            placeholder="Optional"
                            class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
                        />
                        <!--
                            Left blank rather than defaulting to a multiple. Guessing a multiplier
                            would invent an agreement, and overtime paid at the wrong rate is worse
                            than overtime visibly unpaid and queried.
                        -->
                        <p class="mt-1 text-xs text-stone-400">
                            Left blank, overtime is recorded but not paid.
                        </p>
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-xs text-stone-500" for="effective">Applies from</label>
                    <input
                        id="effective"
                        v-model="form.effective_from"
                        type="date"
                        class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
                    />
                </div>

                <div>
                    <label class="mb-1 block text-xs text-stone-500" for="note">Note</label>
                    <input
                        id="note"
                        v-model="form.note"
                        type="text"
                        placeholder="Why it changed, e.g. annual raise"
                        class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
                    />
                </div>
            </div>

            <p v-if="error" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
                {{ error }}
            </p>

            <div class="mt-4 flex gap-2">
                <button
                    type="button"
                    class="flex-1 rounded-lg bg-amber-900 py-2.5 text-sm font-medium text-white disabled:opacity-50"
                    :disabled="! canSave || saving"
                    @click="submit"
                >
                    {{ saving ? 'Saving…' : 'Save rate' }}
                </button>
                <button
                    type="button"
                    class="rounded-lg border border-stone-300 px-4 py-2.5 text-sm text-stone-700"
                    @click="emit('close')"
                >
                    Cancel
                </button>
            </div>
        </div>
    </div>
</template>
