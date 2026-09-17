<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    /** Existing shift, or a blank one for a new shift. `id: null` means create. */
    shift: { type: Object, required: true },
    employees: { type: Array, default: () => [] },
    outlets: { type: Array, default: () => [] },
    saving: { type: Boolean, default: false },
    error: { type: String, default: null },
    fieldErrors: { type: Object, default: null },
});

const emit = defineEmits(['save', 'close']);

const form = ref({ ...props.shift });

/*
 * Copy once on open, and again if the parent swaps in a different shift. Without the watch,
 * opening a second shift in the same session would show the first one's values — which is
 * how someone silently overwrites a shift they never looked at.
 */
watch(() => props.shift, (value) => {
    form.value = { ...value };
}, { deep: true });

const isEdit = computed(() => form.value.id !== null);

const canSave = computed(() => form.value.employee_id !== ''
    && form.value.outlet_id !== ''
    && form.value.starts_at !== ''
    && form.value.ends_at !== '');

/**
 * Whether the times are the wrong way round.
 *
 * Caught here as well as on the server so the manager sees it before a round trip, and
 * because a negative duration is the one mistake that produces a nonsense record rather
 * than an obvious error.
 */
const endsBeforeStart = computed(() => form.value.starts_at !== ''
    && form.value.ends_at !== ''
    && form.value.ends_at <= form.value.starts_at);

function fieldError(field) {
    return props.fieldErrors?.[field]?.[0] ?? null;
}

function submit() {
    if (! canSave.value || endsBeforeStart.value) {
        return;
    }

    emit('save', {
        employee_id: Number(form.value.employee_id),
        outlet_id: Number(form.value.outlet_id),
        // Sent bare, with no offset. The server attaches the outlet's timezone; sending a
        // zone here would mean trusting the phone's clock and its timezone setting.
        starts_at: form.value.starts_at,
        ends_at: form.value.ends_at,
        position: form.value.position || null,
        note: form.value.note || null,
        allow_overlap: form.value.allow_overlap,
    });
}
</script>

<template>
    <div class="fixed inset-0 z-40 flex items-end justify-center bg-stone-900/40 p-4 sm:items-center">
        <div class="w-full max-w-lg rounded-2xl bg-white p-5">
            <h2 class="text-base font-semibold text-stone-900">
                {{ isEdit ? 'Edit shift' : 'Add a shift' }}
            </h2>

            <p class="mt-1 text-xs text-stone-500">
                Times are local to the outlet.
            </p>

            <div class="mt-4 space-y-3">
                <div>
                    <label class="mb-1 block text-xs text-stone-500" for="employee">Employee</label>
                    <select
                        id="employee"
                        v-model="form.employee_id"
                        class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
                    >
                        <option value="">Choose someone…</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">
                            {{ e.name }}
                        </option>
                    </select>
                    <p v-if="fieldError('employee_id')" class="mt-1 text-xs text-red-600">
                        {{ fieldError('employee_id') }}
                    </p>
                </div>

                <div>
                    <label class="mb-1 block text-xs text-stone-500" for="outlet">Outlet</label>
                    <select
                        id="outlet"
                        v-model="form.outlet_id"
                        class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
                    >
                        <option value="">Choose an outlet…</option>
                        <option v-for="o in outlets" :key="o.id" :value="o.id">{{ o.name }}</option>
                    </select>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs text-stone-500" for="starts">Starts</label>
                        <input
                            id="starts"
                            v-model="form.starts_at"
                            type="datetime-local"
                            class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
                        />
                    </div>

                    <div>
                        <label class="mb-1 block text-xs text-stone-500" for="ends">Ends</label>
                        <input
                            id="ends"
                            v-model="form.ends_at"
                            type="datetime-local"
                            class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
                        />
                    </div>
                </div>

                <p v-if="endsBeforeStart" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
                    The shift ends before it starts.
                </p>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs text-stone-500" for="position">Position</label>
                        <input
                            id="position"
                            v-model="form.position"
                            type="text"
                            placeholder="Kitchen, Front…"
                            class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
                        />
                    </div>

                    <div>
                        <label class="mb-1 block text-xs text-stone-500" for="note">Note</label>
                        <input
                            id="note"
                            v-model="form.note"
                            type="text"
                            class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
                        />
                    </div>
                </div>

                <!--
                    Off by default, because a double-booking is almost always a mistake. Offered
                    because a split shift or a handover genuinely needs it, and the alternative
                    would be cancelling a shift to make room for its replacement.
                -->
                <label class="flex items-start gap-2 text-sm text-stone-700">
                    <input v-model="form.allow_overlap" type="checkbox" class="mt-0.5" />
                    <span>
                        Allow a clash with another shift
                        <span class="block text-xs text-stone-400">
                            Only needed for a split shift or a handover.
                        </span>
                    </span>
                </label>
            </div>

            <p v-if="error" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
                {{ error }}
            </p>

            <div class="mt-4 flex gap-2">
                <button
                    type="button"
                    class="flex-1 rounded-lg bg-amber-900 py-2.5 text-sm font-medium text-white disabled:opacity-50"
                    :disabled="! canSave || endsBeforeStart || saving"
                    @click="submit"
                >
                    {{ saving ? 'Saving…' : (isEdit ? 'Save changes' : 'Add to roster') }}
                </button>
                <button
                    type="button"
                    class="rounded-lg border border-stone-300 px-4 py-2.5 text-sm text-stone-700"
                    @click="emit('close')"
                >
                    Cancel
                </button>
            </div>

            <p v-if="isEdit" class="mt-3 text-xs text-stone-400">
                Moving the times cancels this shift and writes a replacement, so the change stays
                visible on the record.
            </p>
        </div>
    </div>
</template>
