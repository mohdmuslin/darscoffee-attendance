<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import { usePunchStore } from './stores/punch';
import ScanStep from './components/ScanStep.vue';
import PinStep from './components/PinStep.vue';
import ActionStep from './components/ActionStep.vue';
import MyHours from './components/MyHours.vue';

const punch = usePunchStore();

const showHours = ref(false);
const scannedToken = ref(null);

/**
 * Drain queued punches when the phone comes back online.
 *
 * `online` is the event that matters here: the employee's first sign of signal returning is
 * usually the browser's, well before they press anything. Waiting for the next action would leave
 * a queued clock-out sitting on the phone while they walk home.
 */
function onOnline() {
    punch.drainQueue();
}

onMounted(async () => {
    /*
     * Try to recover a session before showing the scanner. A phone that locked
     * mid-shift should return to the action screen, not to "scan the code again" when
     * the employee only wanted to end a break.
     */
    await punch.resume();

    /*
     * Anything queued from a previous visit is sent now. A reload is a common way for someone to
     * reconnect deliberately, and waiting for them to press a button means the queue drains at a
     * moment of their choosing rather than at the first opportunity.
     */
    await punch.drainQueue();

    window.addEventListener('online', onOnline);
});

onUnmounted(() => {
    window.removeEventListener('online', onOnline);
});

function onScanned(token) {
    scannedToken.value = token;
    punch.step = 'pin';
}

function onPinBack() {
    scannedToken.value = null;
    punch.step = 'scan';
}
</script>

<template>
    <main class="mx-auto flex min-h-dvh max-w-md flex-col px-4 py-6">
        <header class="mb-6 text-center">
            <p class="text-lg font-semibold text-stone-900">Dars Coffee</p>
            <p class="text-xs text-stone-500">Staff clock-in</p>
        </header>

        <p v-if="punch.error" class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ punch.error }}
        </p>

        <!--
            Punches waiting on this phone.
            
            Shown because an employee who cannot tell whether their clock-out was recorded will
            press the button again — and a retry is what creates a duplicate. A visible count is the
            cheapest way to stop that, and it sets the expectation that the punch is safe rather than
            lost.
        -->
        <p
            v-if="punch.hasQueued"
            class="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900"
        >
            <template v-if="punch.syncing">
                Sending {{ punch.queued }} saved punch(es)…
            </template>
            <template v-else>
                {{ punch.queued }} punch(es) saved on this phone, waiting for signal.
                <span v-if="punch.queuedNeedsManager" class="mt-1 block font-medium">
                    This outlet needs a photo, and photos cannot be saved offline — tell a manager
                    so they can add it.
                </span>
            </template>
        </p>

        <!--
            The steps are a linear sequence rather than routes. The flow is short, and
            URL states would need guarding without making the screen any easier to use
            one-handed in a kitchen.
        -->
        <ScanStep v-if="punch.step === 'scan'" @scanned="onScanned" />

        <PinStep
            v-else-if="punch.step === 'pin'"
            :token="scannedToken"
            @back="onPinBack"
        />

        <ActionStep v-else @show-hours="showHours = true" />

        <MyHours v-if="showHours" @close="showHours = false" />
    </main>
</template>
