<script setup>
import { computed, onMounted, ref } from 'vue';
import { outletApi } from '../services/api';
import { useOutletStore } from '../stores/outlets';

const props = defineProps({
    id: { type: [String, Number], required: true },
});

const outlets = useOutletStore();

const sheet = ref(null);
const busy = ref(false);
const error = ref(null);
const notice = ref(null);
/** True when the outlet simply has no code yet, rather than a real failure. */
const noCodeYet = ref(false);

const outlet = computed(() => outlets.outlets.find((o) => String(o.id) === String(props.id)));

onMounted(async () => {
    await outlets.load();
    await loadSheet();
});

async function loadSheet() {
    error.value = null;
    noCodeYet.value = false;

    try {
        sheet.value = await outletApi.printSheet(props.id);
    } catch (e) {
        /*
         * "No code yet" is a normal state on a new outlet, not a failure — and the
         * screen must offer a way to create one. Reporting it as an error left the
         * page at a dead end, which is exactly what happens the first time anyone
         * sets an outlet up.
         */
        if (e.code === 'NO_ACTIVE_TOKEN') {
            noCodeYet.value = true;
            sheet.value = null;

            return;
        }

        error.value = e.message;
    }
}

/**
 * Issue a replacement (or first) code.
 *
 * This is the REPRINT action, and reprinting is the only way to revoke a printed
 * code. It is deliberately one click with a single confirmation: if revoking a leaked
 * sheet were cumbersome, it would not get done.
 */
async function reprint() {
    const isFirst = noCodeYet.value;

    const confirmed = isFirst
        ? true
        : window.confirm(
            'Issue a new clock-in code for this outlet?\n\n'
            + 'Any code currently displayed or pinned up will STOP WORKING immediately.\n'
            + 'Print and display the new one before staff need to clock in.',
        );

    if (! confirmed) {
        return;
    }

    busy.value = true;
    error.value = null;
    notice.value = null;

    try {
        const result = await outlets.regenerateToken(props.id);

        notice.value = result.message ?? 'New code issued. The previous one was revoked.';

        await loadSheet();
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}

async function revoke() {
    if (! window.confirm(
        'Revoke the code without issuing a new one?\n\n'
        + 'Nobody will be able to clock in at this outlet until a new code is issued.',
    )) {
        return;
    }

    busy.value = true;
    error.value = null;

    try {
        const result = await outlets.revokeToken(props.id);

        notice.value = result.message ?? 'Code revoked.';

        await loadSheet();
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}

/**
 * The QR image itself.
 *
 * Rendered by a public QR service rather than generating a file server-side: the code
 * changes on every reprint, and storing an image per reprint would accumulate files
 * nothing cleans up. The token is not a secret in transit — it is displayed on a
 * counter for anyone standing there to photograph.
 */
const qrUrl = computed(() => {
    if (! sheet.value?.scan_url) {
        return null;
    }

    return 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&margin=8&data='
        + encodeURIComponent(sheet.value.scan_url);
});
</script>

<template>
    <div class="mx-auto max-w-3xl">
        <h1 class="text-lg font-semibold text-stone-900">
            Clock-in code
        </h1>
        <p class="mt-1 text-sm text-stone-500">
            {{ outlet?.name ?? 'Outlet' }}
            <span v-if="outlet"> · {{ outlet.token_mode_label }}</span>
        </p>

        <p v-if="error" class="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p>
        <p v-if="notice" class="mt-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ notice }}</p>

        <!--
            A new outlet has no code yet. That is a normal starting point, not a
            failure, so it gets an explanation and the one action that resolves it
            rather than an error the user cannot act on.
        -->
        <div
            v-if="noCodeYet"
            class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-6 text-center"
        >
            <p class="font-medium text-amber-900">This outlet has no clock-in code yet</p>
            <p class="mx-auto mt-1 max-w-md text-sm text-amber-900">
                Until a code is issued and displayed, nobody can clock in here. Staff scan
                it with their own phone, so it needs to be visible at the counter.
            </p>

            <button
                type="button"
                class="mt-4 rounded-lg bg-amber-900 px-4 py-2.5 text-sm font-medium text-white disabled:opacity-50"
                :disabled="busy"
                @click="reprint"
            >
                {{ busy ? 'Issuing…' : 'Issue a clock-in code' }}
            </button>
        </div>

        <div v-if="sheet" class="mt-4 rounded-xl border border-stone-200 bg-white p-6">
            <div class="flex flex-col items-center gap-4 sm:flex-row sm:items-start">
                <img
                    v-if="qrUrl"
                    :src="qrUrl"
                    alt="Clock-in QR code for this outlet"
                    class="h-48 w-48 rounded-lg border border-stone-200"
                />
                <div
                    v-else
                    class="flex h-48 w-48 items-center justify-center rounded-lg bg-stone-100 text-xs text-stone-400"
                >
                    no code image
                </div>

                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-stone-800">How this works</p>
                    <p class="mt-1 text-sm text-stone-600">{{ sheet.instructions }}</p>

                    <dl class="mt-4 space-y-1 text-xs text-stone-500">
                        <div class="flex gap-2">
                            <dt class="w-28 shrink-0">Issued</dt>
                            <dd>{{ new Date(sheet.token.issued_at).toLocaleString() }}</dd>
                        </div>
                        <div class="flex gap-2">
                            <dt class="w-28 shrink-0">Expires</dt>
                            <dd>{{ sheet.token.expires_at ? new Date(sheet.token.expires_at).toLocaleString() : 'Not until replaced' }}</dd>
                        </div>
                        <div class="flex gap-2">
                            <dt class="w-28 shrink-0">Replaced</dt>
                            <dd>{{ sheet.token.generations }} time(s) since the outlet was created</dd>
                        </div>
                    </dl>

                    <!-- The URL is shown so it can be typed if a printer is unavailable.
                         A code with no fallback strands staff when the printer is out of
                         paper, which is exactly when it matters. -->
                    <p class="mt-4 break-all rounded-lg bg-stone-50 px-3 py-2 text-xs text-stone-500">
                        {{ sheet.scan_url }}
                    </p>
                </div>
            </div>

            <div class="mt-6 flex flex-wrap gap-2">
                <button
                    type="button"
                    class="rounded-lg bg-amber-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                    :disabled="busy"
                    @click="reprint"
                >
                    {{ busy ? 'Working…' : 'Issue new code (replaces this one)' }}
                </button>

                <button
                    type="button"
                    class="rounded-lg border border-red-300 px-4 py-2 text-sm font-medium text-red-700 disabled:opacity-50"
                    :disabled="busy"
                    @click="revoke"
                >
                    Revoke without replacing
                </button>

                <button
                    type="button"
                    class="rounded-lg border border-stone-300 px-4 py-2 text-sm font-medium"
                    @click="window.print()"
                >
                    Print this page
                </button>
            </div>

            <p class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">
                <strong>Lost or photographed sheet?</strong>
                Issue a new code — the old one stops working immediately.
                This is the only way to revoke a printed code, so do not delay it.
            </p>
        </div>

        <p v-else-if="!error && !noCodeYet" class="mt-4 text-sm text-stone-500">Loading the code…</p>

        <RouterLink
            :to="{ name: 'outlets' }"
            class="mt-6 inline-block text-sm text-amber-900 underline"
        >
            Back to outlets
        </RouterLink>
    </div>
</template>
