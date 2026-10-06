<script setup>
import { computed } from 'vue';

/*
 * Shows the clock-in and clock-out photographs for one punch, side by side.
 *
 * WHY THIS EXISTS
 * Photographs were being captured and stored but were never viewable anywhere in the
 * console — `has_photo` was returned by the API and no screen read it. The whole reason the
 * photos are collected is to settle a dispute about when somebody arrived or left, and a
 * record nobody can look at does not settle anything.
 *
 * WHY SIDE BY SIDE
 * The pair is the evidence: a clock-in photo and the times it recorded are only meaningful
 * together, and having to close one to open the other is how a manager concludes the second
 * one is missing.
 */

const props = defineProps({
    /** The entry this photo belongs to. */
    entry: { type: Object, required: true },

    /** Headline, e.g. the employee's name. */
    title: { type: String, default: 'Punch photo' },
});

const emit = defineEmits(['close']);

const shots = computed(() => [
    {
        key: 'started',
        label: 'Clock in',
        url: props.entry.started_photo_url,
        at: props.entry.started_at,
    },
    {
        key: 'ended',
        label: 'Clock out',
        url: props.entry.ended_photo_url,
        at: props.entry.ended_at,
    },
].filter((shot) => Boolean(shot.url)));
</script>

<template>
    <div
        class="fixed inset-0 z-50 flex flex-col bg-stone-900/95 px-4 py-6"
        @click.self="emit('close')"
    >
        <div class="mx-auto flex w-full max-w-3xl flex-1 flex-col overflow-y-auto">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-semibold text-stone-100">{{ title }}</p>
                    <p class="text-xs text-stone-400">
                        {{ entry.type_label }}
                        <span v-if="entry.outlet"> · {{ entry.outlet }}</span>
                    </p>
                </div>

                <button
                    type="button"
                    class="rounded-lg border border-stone-600 px-3 py-1.5 text-sm font-medium text-stone-200"
                    @click="emit('close')"
                >
                    Close
                </button>
            </div>

            <!--
                Stacked on a phone and side by side on anything wider, because a manager
                checking a dispute may well be doing it from their own phone.
            -->
            <div class="mt-4 grid flex-1 gap-4 sm:grid-cols-2">
                <figure
                    v-for="shot in shots"
                    :key="shot.key"
                    class="flex flex-col overflow-hidden rounded-2xl bg-black"
                >
                    <img
                        :src="shot.url"
                        :alt="`${shot.label} photograph`"
                        class="aspect-[4/3] w-full object-cover"
                    />
                    <figcaption class="bg-stone-800 px-3 py-2 text-xs text-stone-200">
                        <span class="font-medium">{{ shot.label }}</span>
                        <span class="text-stone-400">
                            · {{ shot.at ? new Date(shot.at).toLocaleString() : '—' }}
                        </span>
                    </figcaption>
                </figure>
            </div>

            <!--
                Stated rather than silently omitted. An open segment genuinely has only one
                photo, and a manager who is not told that will assume the second photo failed
                to save.
            -->
            <p
                v-if="shots.length === 1"
                class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900"
            >
                Only one photograph exists for this row. An open segment — clocked in but not
                yet out — has no clock-out photo, which is expected and not a fault.
            </p>

            <p class="mt-3 text-xs text-stone-400">
                Photographs are deleted after the retention period. An entry with
                <code class="rounded bg-stone-800 px-1">—</code> instead of a photo link has
                passed that window, or was recorded before photos were required.
            </p>
        </div>
    </div>
</template>
