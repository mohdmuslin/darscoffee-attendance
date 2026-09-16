<script setup>
import { ref } from 'vue';

/**
 * Root of the punch PWA.
 *
 * Phase 1 shows the shell and confirms the app is wired up. The scan → PIN →
 * photo flow lands in Phase 2; keeping a visible placeholder here means the build
 * can be verified on a real phone before that flow exists, rather than debugging a
 * blank screen later.
 */
const ready = ref(true);
</script>

<template>
    <main class="mx-auto flex min-h-dvh max-w-md flex-col items-center justify-center px-6 py-10">
        <div class="w-full rounded-2xl border border-stone-200 bg-white p-8 text-center shadow-sm">
            <h1 class="text-xl font-semibold text-stone-900">Dars Coffee</h1>
            <p class="mt-1 text-sm text-stone-500">Staff clock-in</p>

            <p v-if="ready" class="mt-6 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">
                The clock-in flow is being built. Phase 1 is the foundation: outlets,
                employees and codes.
            </p>
        </div>
    </main>
</template>
