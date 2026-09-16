<script setup>
import { ref } from 'vue';

// Placeholder until the console API and stores land. The dashboard is where
// unreviewed anomalies will surface, since an unapproved correction is unpaid time.
const outlets = ref([]);
</script>

<template>
    <div>
        <h1 class="text-lg font-semibold text-stone-900">Dashboard</h1>
        <p class="mt-1 text-sm text-stone-500">
            Who is clocked in, today's hours, and anything needing review.
        </p>

        <div class="mt-6 rounded-xl border border-stone-200 bg-white p-6 text-sm text-stone-500">
            <p v-if="outlets.length === 0">
                The foundation is in place. Employee management, codes and the
                clock-in flow are next.
            </p>
        </div>
    </div>
</template>
