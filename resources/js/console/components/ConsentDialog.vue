<script setup>
import { computed, onMounted, ref } from 'vue';
import { employeeApi } from '../services/api';
import { useEmployeeStore } from '../stores/employees';

/**
 * Record or withdraw PDPA consent for a staff member's photograph.
 *
 * Deliberately a form rather than a checkbox. A consent record exists to be defended, so it has
 * to capture what was agreed to and how strongly — a bare tick would answer none of the questions
 * an employer is actually asked, and would look like compliance while providing none.
 */
const props = defineProps({
    employeeId: { type: Number, required: true },
});

const emit = defineEmits(['close']);
const employees = useEmployeeStore();

/**
 * Resolved from the store rather than passed in.
 *
 * After consent is recorded the store reloads, and this reads the fresh row. Handed a snapshot
 * instead, the dialog would keep showing "no consent on record" immediately after the save —
 * which looks like the save failed, and invites the user to press the button again.
 */
const employee = computed(
    () => employees.employees.find((e) => e.id === props.employeeId) ?? { id: props.employeeId },
);

const methods = ref([]);
const noticeVersion = ref(null);

const method = ref('verbal');
const note = ref('');
const busy = ref(false);
const error = ref(null);

/** Withdrawal needs a reason, so it is a separate mode of the same dialog. */
const mode = ref('view');

onMounted(async () => {
    // Not consented and not withdrawn: the only useful action is to record it.
    if (! employee.value.has_consent && ! employee.value.consent_withdrawn) {
        mode.value = 'record';
    }

    try {
        const data = await employeeApi.consentMethods();

        methods.value = data.methods;
        noticeVersion.value = data.notice_version;

        /*
         * Defaults to the STRONGEST method rather than the first. The list is ordered weakest
         * first, so defaulting to it would make verbal consent the path of least resistance — and
         * whoever is entering the data may not read the options at all.
         */
        method.value = methods.value.at(-1)?.value ?? 'verbal';
    } catch (err) {
        error.value = err.message;
    }
});

const isWithdrawn = computed(() => employee.value.consent_withdrawn === true);

async function record() {
    busy.value = true;
    error.value = null;

    try {
        await employees.recordConsent(props.employeeId, {
            method: method.value,
            note: note.value || null,
        });

        mode.value = 'view';
    } catch (err) {
        error.value = err.message;
    } finally {
        busy.value = false;
    }
}

async function withdraw() {
    busy.value = true;
    error.value = null;

    try {
        await employees.withdrawConsent(props.employeeId, note.value || null);

        mode.value = 'view';
    } catch (err) {
        error.value = err.message;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4"
        @click.self="emit('close')"
    >
        <div class="w-full max-w-md rounded-2xl bg-white p-6">
            <h2 class="font-semibold text-stone-900">
                Photograph consent — {{ employee.name }}
            </h2>

            <p v-if="error" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
                {{ error }}
            </p>

            <!-- Current state -->
            <div class="mt-3 rounded-lg bg-stone-50 px-3 py-2 text-sm">
                <template v-if="employee.has_consent">
                    <p class="font-medium text-emerald-800">
                        Consent on record
                        <span v-if="employee.consent_method_label">
                            — {{ employee.consent_method_label }}
                        </span>
                    </p>
                    <p class="mt-0.5 text-xs text-stone-600">
                        Given {{ employee.consent_at?.slice(0, 10) }}
                        <template v-if="employee.consent_version">
                            against notice v{{ employee.consent_version }}
                        </template>
                        <template v-if="employee.consent_recorded_by">
                            , recorded by {{ employee.consent_recorded_by }}
                        </template>.
                    </p>
                </template>

                <template v-else-if="isWithdrawn">
                    <p class="font-medium text-red-800">Consent withdrawn</p>
                    <p class="mt-0.5 text-xs text-stone-600">
                        Withdrawn {{ employee.consent_withdrawn_at?.slice(0, 10) }}. They will not
                        be photographed at the punch screen.
                        <template v-if="employee.consent_withdrawal_note">
                            Note: {{ employee.consent_withdrawal_note }}
                        </template>
                    </p>
                </template>

                <template v-else>
                    <p class="font-medium text-amber-800">No consent on record</p>
                    <p class="mt-0.5 text-xs text-stone-600">
                        Their photograph is held without a consent record. Record it below, or ask
                        them whether they would prefer it removed.
                    </p>
                </template>
            </div>

            <!-- Record -->
            <template v-if="mode === 'record'">
                <label class="mt-4 block text-sm font-medium">
                    How was consent given?
                    <select
                        v-model="method"
                        class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2"
                    >
                        <option v-for="m in methods" :key="m.value" :value="m.value">
                            {{ m.label }}
                        </option>
                    </select>
                </label>

                <p class="mt-1 text-xs text-stone-500">
                    They were told their photograph and time data are held for attendance, and
                    agreed.
                    <template v-if="noticeVersion">
                        Notice version {{ noticeVersion }}.
                    </template>
                </p>

                <label class="mt-3 block text-sm font-medium">
                    Note (optional)
                    <input
                        v-model="note"
                        type="text"
                        maxlength="255"
                        placeholder="e.g. signed form kept in the office"
                        class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2"
                    />
                </label>
            </template>

            <!-- Withdraw -->
            <template v-else-if="mode === 'withdraw'">
                <p class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">
                    Their profile photo will be deleted and no further photographs will be taken.
                    <br /><br />
                    Photographs already attached to past punches are <strong>kept</strong> — some may
                    be evidence in an open dispute, and erasing them is a decision for the owner.
                </p>

                <label class="mt-3 block text-sm font-medium">
                    Reason (optional)
                    <input
                        v-model="note"
                        type="text"
                        maxlength="255"
                        class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2"
                    />
                </label>
            </template>

            <div class="mt-5 flex flex-wrap justify-end gap-2">
                <button
                    type="button"
                    class="rounded-lg border border-stone-300 px-3 py-2 text-sm"
                    @click="emit('close')"
                >
                    Close
                </button>

                <template v-if="mode === 'view'">
                    <button
                        v-if="employee.has_consent || isWithdrawn"
                        type="button"
                        class="rounded-lg border border-stone-300 px-3 py-2 text-sm"
                        @click="mode = 'record'; note = ''"
                    >
                        Record again
                    </button>
                    <button
                        v-if="! isWithdrawn"
                        type="button"
                        class="rounded-lg border border-red-300 px-3 py-2 text-sm font-medium text-red-700"
                        @click="mode = 'withdraw'; note = ''"
                    >
                        Withdraw consent
                    </button>
                    <button
                        v-if="! employee.has_consent && ! isWithdrawn"
                        type="button"
                        class="rounded-lg bg-amber-900 px-4 py-2 text-sm font-medium text-white"
                        @click="mode = 'record'"
                    >
                        Record consent
                    </button>
                </template>

                <template v-else>
                    <button
                        type="button"
                        class="rounded-lg border border-stone-300 px-3 py-2 text-sm"
                        @click="mode = 'view'"
                    >
                        Back
                    </button>
                    <button
                        type="button"
                        class="rounded-lg px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                        :class="mode === 'withdraw' ? 'bg-red-700' : 'bg-amber-900'"
                        :disabled="busy"
                        @click="mode === 'withdraw' ? withdraw() : record()"
                    >
                        {{ mode === 'withdraw' ? 'Withdraw consent' : 'Record consent' }}
                    </button>
                </template>
            </div>
        </div>
    </div>
</template>
