<script setup>
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();

const email = ref('');
const password = ref('');
const showPassword = ref(false);

async function submit() {
    const ok = await auth.login(email.value, password.value);

    if (! ok) {
        return;
    }

    /*
     * Return to where they were headed, but only ever within this app. Honouring an
     * arbitrary `redirect` value is how a login form becomes an open redirect.
     */
    const target = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/')
        ? route.query.redirect
        : '/dashboard';

    router.push(target);
}
</script>

<template>
    <div class="mx-auto flex min-h-dvh max-w-sm flex-col justify-center px-6">
        <div class="rounded-2xl border border-stone-200 bg-white p-8 shadow-sm">
            <h1 class="text-lg font-semibold text-stone-900">Dars Coffee</h1>
            <p class="mt-1 text-sm text-stone-500">Attendance management</p>

            <form class="mt-6 space-y-4" @submit.prevent="submit">
                <label class="block text-sm font-medium text-stone-700">
                    Email
                    <input
                        v-model="email"
                        type="email"
                        required
                        autocomplete="username"
                        class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2"
                    />
                </label>

                <div class="text-sm font-medium text-stone-700">
                    Password
                    <div class="mt-1 flex gap-2">
                        <input
                            v-model="password"
                            :type="showPassword ? 'text' : 'password'"
                            required
                            autocomplete="current-password"
                            class="block w-full rounded-lg border border-stone-300 px-3 py-2"
                        />
                        <!--
                            Visible on request rather than by default: a shop counter is
                            not private, but mistyping a long password repeatedly is real.
                        -->
                        <button
                            type="button"
                            class="shrink-0 rounded-lg border border-stone-300 px-3 text-xs text-stone-600"
                            @click="showPassword = !showPassword"
                        >
                            {{ showPassword ? 'Hide' : 'Show' }}
                        </button>
                    </div>
                </div>

                <p v-if="auth.error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
                    {{ auth.error }}
                </p>

                <button
                    type="submit"
                    class="w-full rounded-lg bg-amber-900 px-4 py-2.5 font-medium text-white disabled:opacity-50"
                    :disabled="auth.loading"
                >
                    {{ auth.loading ? 'Signing in…' : 'Sign in' }}
                </button>
            </form>
        </div>

        <p class="mt-4 text-center text-xs text-stone-400">
            Staff clock in from their own phone — no sign-in needed.
        </p>
    </div>
</template>
