<script setup>
import { onMounted, ref } from 'vue';
import { usePunchStore } from './stores/punch';
import ScanStep from './components/ScanStep.vue';
import PinStep from './components/PinStep.vue';
import ActionStep from './components/ActionStep.vue';
import MyHours from './components/MyHours.vue';

const punch = usePunchStore();

const showHours = ref(false);
const scannedToken = ref(null);

onMounted(async () => {
    /*
     * Try to recover a session before showing the scanner. A phone that locked
     * mid-shift should return to the action screen, not to "scan the code again" when
     * the employee only wanted to end a break.
     */
    await punch.resume();
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
