<script setup>
import { onMounted } from 'vue';
import { usePunchStore } from '../stores/punch';

const emit = defineEmits(['close']);

const punch = usePunchStore();

onMounted(() => punch.loadHours());
</script>

<template>
    <div class="fixed inset-0 z-40 flex flex-col bg-white px-4 py-6">
        <div class="mx-auto flex w-full max-w-md flex-1 flex-col">
            <div class="flex items-center justify-between">
                <h1 class="text-lg font-semibold text-stone-900">My hours</h1>
                <button
                    type="button"
                    class="text-sm text-stone-500 underline"
                    @click="emit('close')"
                >
                    Close
                </button>
            </div>

            <p v-if="punch.loading" class="mt-6 text-sm text-stone-500">Loading…</p>

            <template v-else-if="punch.hours">
                <div class="mt-4 rounded-2xl border border-stone-200 p-5">
                    <p class="text-xs text-stone-500">
                        Week starting {{ punch.hours.week_starting }}
                    </p>
                    <p class="mt-1 text-3xl font-semibold text-stone-900">
                        {{ punch.hours.total_label }}
                    </p>
                </div>

                <!--
                    Breaks are excluded here, matching the timesheet. An employee who
                    compares this to a number a manager quoted needs them to agree, or
                    the difference looks like it is hiding something.
                -->
                <ul class="mt-4 divide-y divide-stone-100 rounded-2xl border border-stone-200">
                    <li
                        v-for="day in punch.hours.days"
                        :key="day.date"
                        class="flex items-center justify-between px-4 py-3"
                    >
                        <span class="text-sm text-stone-700">
                            {{ day.weekday }}
                            <span class="ml-2 text-xs text-stone-400">{{ day.date }}</span>
                        </span>
                        <span class="text-sm font-medium text-stone-900">{{ day.label }}</span>
                    </li>
                </ul>

                <p v-if="punch.hours.days.length === 0" class="mt-4 text-sm text-stone-500">
                    No completed shifts this week yet.
                </p>

                <p class="mt-6 text-xs text-stone-400">
                    Only completed shifts are shown. If something looks wrong, ask a manager —
                    they can correct it and the change is recorded.
                </p>
            </template>
        </div>
    </div>
</template>
