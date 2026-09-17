<script setup>
import { onMounted, reactive, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { useOutletStore } from '../stores/outlets';

const auth = useAuthStore();
const outlets = useOutletStore();

const showForm = ref(false);
const saving = ref(false);
const error = ref(null);
const form = reactive({
    code: '',
    name: '',
    address: '',
    token_mode: 'rotating',
    qr_ttl_seconds: 90,
    requires_photo: true,
});

onMounted(() => outlets.load());

async function create() {
    saving.value = true;
    error.value = null;

    try {
        await outlets.create({ ...form });
        showForm.value = false;
        Object.assign(form, { code: '', name: '', address: '' });
        await outlets.load();
    } catch (e) {
        error.value = e.message;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold text-stone-900">Outlets</h1>
                <p class="text-xs text-stone-500">
                    Each outlet has its own clock-in code.
                </p>
            </div>

            <!-- Adding a location is an owner decision; a manager sees no button
                 rather than a button that fails. -->
            <button
                v-if="auth.isOwner"
                type="button"
                class="rounded-lg bg-amber-900 px-3 py-2 text-sm font-medium text-white"
                @click="showForm = !showForm"
            >
                {{ showForm ? 'Cancel' : 'Add outlet' }}
            </button>
        </div>

        <form
            v-if="showForm"
            class="mt-4 space-y-4 rounded-xl border border-stone-200 bg-white p-4"
            @submit.prevent="create"
        >
            <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="text-sm font-medium text-stone-700">
                    Short code
                    <input
                        v-model="form.code"
                        required
                        placeholder="SG-RAMAL"
                        class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2"
                    />
                </label>

                <label class="text-sm font-medium text-stone-700">
                    Name
                    <input
                        v-model="form.name"
                        required
                        class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2"
                    />
                </label>
            </div>

            <label class="block text-sm font-medium text-stone-700">
                Address (optional)
                <input
                    v-model="form.address"
                    class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2"
                />
            </label>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="text-sm font-medium text-stone-700">
                    Clock-in code style
                    <select
                        v-model="form.token_mode"
                        class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2"
                    >
                        <option value="rotating">Rotating — shown on a device, expires</option>
                        <option value="printed">Printed — a sheet, valid until reprinted</option>
                    </select>
                    <span class="mt-1 block text-xs text-stone-400">
                        Rotating is stronger: a photograph of it is useless in 90 seconds.
                    </span>
                </label>

                <label class="flex items-center gap-2 self-end text-sm font-medium text-stone-700">
                    <input v-model="form.requires_photo" type="checkbox" class="accent-amber-900" />
                    Require a photo on every punch
                </label>
            </div>

            <div class="flex justify-end">
                <button
                    type="submit"
                    class="rounded-lg bg-amber-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                    :disabled="saving"
                >
                    {{ saving ? 'Adding…' : 'Add outlet' }}
                </button>
            </div>
        </form>

        <div class="mt-4 space-y-3">
            <div
                v-for="outlet in outlets.outlets"
                :key="outlet.id"
                class="rounded-xl border border-stone-200 bg-white p-4"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-medium text-stone-900">
                            {{ outlet.name }}
                            <span v-if="!outlet.is_active" class="ml-1 rounded bg-stone-200 px-1.5 py-0.5 text-[10px]">
                                inactive
                            </span>
                        </p>
                        <p class="text-xs text-stone-500">
                            {{ outlet.code }} · {{ outlet.token_mode_label }}
                            · {{ outlet.employees_count }} employee(s)
                        </p>
                    </div>

                    <div class="flex shrink-0 flex-wrap items-center gap-2 text-xs">
                        <!-- A missing code means nobody can clock in here, so it is
                             stated outright rather than left to be inferred. -->
                        <span
                            v-if="!outlet.has_live_token"
                            class="rounded-full bg-red-100 px-2 py-1 font-medium text-red-800"
                        >
                            no active code — nobody can clock in
                        </span>
                        <span v-else class="rounded-full bg-emerald-100 px-2 py-1 text-emerald-800">
                            code active
                        </span>

                        <RouterLink
                            :to="{ name: 'outlet-codes', params: { id: outlet.id } }"
                            class="rounded-lg border border-stone-300 px-2.5 py-1.5 font-medium"
                        >
                            Codes &amp; printing
                        </RouterLink>
                    </div>
                </div>
            </div>

            <p
                v-if="!outlets.loading && outlets.outlets.length === 0"
                class="rounded-xl border border-stone-200 bg-white px-4 py-8 text-center text-sm text-stone-500"
            >
                No outlets visible to you.
            </p>
        </div>
    </div>
</template>
