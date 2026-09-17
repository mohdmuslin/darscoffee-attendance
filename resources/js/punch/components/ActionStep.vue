<script setup>
import { computed, ref } from 'vue';
import { usePunchStore } from '../stores/punch';
import PhotoCapture from './PhotoCapture.vue';

const emit = defineEmits(['show-hours']);

const punch = usePunchStore();

const pendingAction = ref(null);
const photoError = ref(null);

/** Remembers what was attempted, so a photo can be attached to the retry. */
const lastAction = ref(null);

/**
 * Confirm before clocking out.
 *
 * Clocking out is the one action that cannot be undone from here and it is easy to
 * tap by accident while putting the phone away. Everything else is recoverable.
 */
const needsConfirm = computed(() => pendingAction.value === 'clock_out');

async function choose(action) {
    photoError.value = null;

    if (punch.needsPhoto) {
        pendingAction.value = action;

        return;
    }

    lastAction.value = action;

    await run(action, null);
}

/**
 * Reopen the camera for the action that was just refused.
 *
 * Reached only when the outlet started requiring photos while this session was already
 * live: the client skipped the camera from a stale setting, the server refused, and the
 * store adopted the server's answer. Without this the employee would be pressing a button
 * that cannot succeed, with no way to reach a camera.
 */
function addPhoto() {
    photoError.value = null;
    pendingAction.value = lastAction.value ?? 'clock_in';
}

async function onCaptured(dataUrl) {
    const action = pendingAction.value;

    if (! action) {
        return;
    }

    if (await run(action, dataUrl)) {
        pendingAction.value = null;
    }
}

async function run(action, photo) {
    const ok = await punch.act(action, photo);

    if (! ok) {
        photoError.value = punch.error;
    }

    return ok;
}

function cancel() {
    pendingAction.value = null;
    photoError.value = null;
}

const tone = {
    primary: 'bg-amber-900 text-white',
    muted: 'border border-stone-300 text-stone-700',
};

function timeOf(iso) {
    if (! iso) {
        return '';
    }

    return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

const stateBadge = {
    clocked_out: 'bg-stone-100 text-stone-600',
    working: 'bg-green-100 text-green-800',
    on_break: 'bg-amber-100 text-amber-900',
};
</script>

<template>
    <div class="flex flex-1 flex-col">
        <div class="rounded-2xl border border-stone-200 bg-white p-5">
            <p class="text-xs uppercase tracking-wide text-stone-500">
                {{ punch.outlet?.name }}
            </p>
            <p class="mt-1 text-lg font-semibold text-stone-900">
                {{ punch.employee?.name }}
            </p>

            <div class="mt-4 flex items-center justify-between">
                <span
                    class="rounded-full px-3 py-1 text-sm font-medium"
                    :class="stateBadge[punch.currentState]"
                >
                    {{ punch.state?.label }}
                </span>

                <span v-if="punch.state?.since" class="text-xs text-stone-500">
                    since {{ timeOf(punch.state.since) }}
                </span>
            </div>

            <!-- Shown after any action. The state label above updates too, but a
                 confirmation that names what happened is what makes people trust it. -->
            <p
                v-if="punch.confirmation"
                class="mt-4 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800"
            >
                {{ punch.confirmation }}
            </p>
        </div>

        <div v-if="punch.state?.today" class="mt-4 grid grid-cols-2 gap-3">
            <div class="rounded-2xl border border-stone-200 bg-white p-4">
                <p class="text-xs text-stone-500">Worked today</p>
                <p class="mt-1 text-2xl font-semibold text-stone-900">
                    {{ punch.state.today.worked_label }}
                </p>
            </div>

            <div class="rounded-2xl border border-stone-200 bg-white p-4">
                <p class="text-xs text-stone-500">Break today</p>
                <p class="mt-1 text-2xl font-semibold text-stone-900">
                    {{ punch.state.today.break_label }}
                </p>
            </div>
        </div>

        <div class="mt-6 space-y-3">
            <button
                v-for="action in punch.actions"
                :key="action.action"
                type="button"
                class="w-full rounded-2xl py-5 text-base font-semibold disabled:opacity-50"
                :class="tone[action.tone] ?? tone.primary"
                :disabled="punch.submitting"
                @click="choose(action.action)"
            >
                {{ action.label }}
            </button>
        </div>

        <p v-if="punch.error && ! pendingAction" class="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ punch.error }}
        </p>

        <!--
            Offered when the server demanded a photo the client did not know was needed.
            A plain error message here would leave the employee with no way forward.
        -->
        <button
            v-if="punch.photoWasDemanded && ! pendingAction"
            type="button"
            class="mt-3 w-full rounded-2xl bg-amber-900 py-4 text-base font-semibold text-white"
            @click="addPhoto"
        >
            Add a photo and try again
        </button>

        <!--
            No "clock out" shortcut here. A long shift can run past midnight, and the
            server assigns the business date from the timestamp — so anyone still on
            shift can just clock out normally and it lands on the right day.
        -->
        <div class="mt-auto space-y-2 pt-8 text-center">
            <button
                type="button"
                class="block w-full text-sm text-stone-600 underline"
                @click="emit('show-hours')"
            >
                My hours this week
            </button>

            <button
                type="button"
                class="block w-full text-sm text-stone-400"
                @click="punch.forget()"
            >
                Done — use someone else's phone
            </button>
        </div>

        <!-- Photo comes first, and every action needs one at this outlet, so a missing
             photo would be rejected server-side anyway. Capturing up front turns that
             rejection into a prompt. -->
        <PhotoCapture
            v-if="pendingAction"
            :action="pendingAction"
            :error="photoError"
            :confirming="needsConfirm"
            @captured="onCaptured"
            @cancel="cancel"
        />
    </div>
</template>
