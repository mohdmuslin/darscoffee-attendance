<script setup>
import { RouterLink, RouterView } from 'vue-router';
</script>

<template>
    <div class="min-h-dvh bg-stone-50">
        <header class="border-b border-stone-200 bg-white">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3">
                <div>
                    <p class="text-sm font-semibold text-stone-900">Dars Coffee</p>
                    <p class="text-xs text-stone-500">Attendance</p>
                </div>

                <nav class="flex items-center gap-4 text-sm">
                    <RouterLink to="/dashboard" class="text-stone-600 hover:text-stone-900">
                        Dashboard
                    </RouterLink>
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-4 py-6">
            <RouterView />
        </main>
    </div>
</template>
