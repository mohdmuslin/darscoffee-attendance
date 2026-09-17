<script setup>
import { ref } from 'vue';
import { usePunchStore } from '../stores/punch';

const props = defineProps({
    token: { type: String, required: true },
});

const emit = defineEmits(['back']);

const punch = usePunchStore();

const pin = ref('');

/**
 * A number pad rather than the OS keyboard.
 *
 * An employee is holding a phone in one hand at a counter. A native keypad shifts the
 * page, and on some phones covers the confirm button entirely.
 */
function press(digit) {
    if (pin.value.length < 6) {
        pin.value += digit;
    }

    // 4 digits is the common length, so a correct PIN is not made to wait for a
    // confirm press it does not need. Longer PINs still work.
    if (pin.value.length === 4) {
        submit();
    }
}

function backspace() {
    pin.value = pin.value.slice(0, -1);
}

async function submit() {
    if (pin.value.length < 4) {
        return;
    }

    const ok = await punch.start(props.token, pin.value);

    if (! ok) {
        // Cleared so the next attempt starts fresh; leaving a wrong PIN on screen
        // invites people to edit it into another guess.
        pin.value = '';
    }
}
</script>

<template>
    <div class="flex flex-1 flex-col">
        <div class="rounded-2xl border border-stone-200 bg-white p-6 text-center">
            <p class="text-sm text-stone-600">Enter your PIN</p>

            <!-- Dots, never the digits: a PIN is short and someone may be watching. -->
            <div class="mt-4 flex justify-center gap-3">
                <span
                    v-for="slot in 6"
                    :key="slot"
                    class="h-3 w-3 rounded-full"
                    :class="pin.length >= slot ? 'bg-amber-900' : 'bg-stone-200'"
                ></span>
            </div>

            <div class="mt-6 grid grid-cols-3 gap-3">
                <button
                    v-for="digit in ['1','2','3','4','5','6','7','8','9']"
                    :key="digit"
                    type="button"
                    class="rounded-xl border border-stone-200 py-4 text-2xl font-medium disabled:opacity-40"
                    :disabled="punch.submitting"
                    @click="press(digit)"
                >
                    {{ digit }}
                </button>

                <button
                    type="button"
                    class="rounded-xl border border-stone-200 py-4 text-sm font-medium disabled:opacity-40"
                    :disabled="punch.submitting"
                    @click="backspace"
                >
                    Delete
                </button>

                <button
                    type="button"
                    class="rounded-xl border border-stone-200 py-4 text-2xl font-medium disabled:opacity-40"
                    :disabled="punch.submitting"
                    @click="press('0')"
                >
                    0
                </button>

                <button
                    type="button"
                    class="rounded-xl bg-amber-900 py-4 text-sm font-medium text-white disabled:opacity-40"
                    :disabled="punch.submitting || pin.length < 4"
                    @click="submit"
                >
                    {{ punch.submitting ? '…' : 'Enter' }}
                </button>
            </div>
        </div>

        <button
            type="button"
            class="mt-4 text-sm text-stone-500 underline"
            @click="emit('back')"
        >
            Scan a different code
        </button>
    </div>
</template>
