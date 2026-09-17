<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useAuthStore } from '../stores/auth';
import { useOutletStore } from '../stores/outlets';
import { userApi } from '../services/api';

const auth = useAuthStore();
const outlets = useOutletStore();

const users = ref([]);
const loading = ref(false);
const saving = ref(false);
const error = ref(null);
const notice = ref(null);
const showForm = ref(false);

const form = reactive({
    name: '',
    email: '',
    password: '',
    role: 'staff',
    outlet_ids: [],
});

onMounted(async () => {
    await outlets.load();
    await load();
});

async function load() {
    loading.value = true;

    try {
        users.value = await userApi.list();
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

async function create() {
    saving.value = true;
    error.value = null;

    try {
        await userApi.create({
            name: form.name,
            email: form.email,
            password: form.password,
            role: form.role,
            // Only meaningful for a manager; the server ignores it otherwise.
            outlet_ids: form.role === 'manager' ? form.outlet_ids : [],
        });

        showForm.value = false;
        Object.assign(form, { name: '', email: '', password: '', role: 'staff', outlet_ids: [] });
        notice.value = 'Account created.';
        await load();
    } catch (e) {
        error.value = e.message;
    } finally {
        saving.value = false;
    }
}

async function toggleActive(user) {
    if (user.id === auth.user?.id) {
        window.alert('You cannot deactivate your own account.');

        return;
    }

    const verb = user.is_active ? 'Deactivate' : 'Reactivate';

    if (! window.confirm(
        `${verb} ${user.name}?\n\n`
        + (user.is_active
            ? 'They will be signed out everywhere immediately.'
            : 'They will be able to sign in again.'),
    )) {
        return;
    }

    try {
        if (user.is_active) {
            await userApi.deactivate(user.id);
        } else {
            await userApi.activate(user.id);
        }

        await load();
    } catch (e) {
        /*
         * The last-owner refusal lands here. Shown verbatim, because the server's
         * message explains what to do instead.
         */
        window.alert(e.message);
    }
}

function outletNames(user) {
    if (user.role === 'owner') {
        return 'All outlets';
    }

    if (user.role === 'staff') {
        return 'Own hours only';
    }

    return user.outlets.length > 0
        ? user.outlets.map((outlet) => outlet.name).join(', ')
        : 'No outlets assigned — sees nothing';
}
</script>

<template>
    <div>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold text-stone-900">Accounts</h1>
                <p class="text-xs text-stone-500">
                    Who can sign in to this system, and what they can see.
                </p>
            </div>

            <button
                type="button"
                class="rounded-lg bg-amber-900 px-3 py-2 text-sm font-medium text-white"
                @click="showForm = !showForm"
            >
                {{ showForm ? 'Cancel' : 'Add account' }}
            </button>
        </div>

        <p v-if="error" class="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p>
        <p v-if="notice" class="mt-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ notice }}</p>

        <form
            v-if="showForm"
            class="mt-4 space-y-4 rounded-xl border border-stone-200 bg-white p-4"
            @submit.prevent="create"
        >
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="text-sm font-medium text-stone-700">
                    Name
                    <input v-model="form.name" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2" />
                </label>

                <label class="text-sm font-medium text-stone-700">
                    Email
                    <input v-model="form.email" type="email" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2" />
                </label>

                <label class="text-sm font-medium text-stone-700">
                    Temporary password
                    <input v-model="form.password" required minlength="8" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2" />
                    <span class="mt-1 block text-xs text-stone-400">
                        At least 8 characters. Ask them to change it after signing in.
                    </span>
                </label>

                <label class="text-sm font-medium text-stone-700">
                    Role
                    <select v-model="form.role" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2">
                        <option value="staff">Staff — own hours only</option>
                        <option value="manager">Manager — their outlets only</option>
                        <option value="owner">Owner — everything</option>
                    </select>
                </label>
            </div>

            <fieldset v-if="form.role === 'manager'" class="rounded-xl border border-stone-200 p-4">
                <legend class="px-1 text-sm font-medium text-stone-700">Which outlets?</legend>
                <p class="text-xs text-stone-500">
                    Required. A manager with no outlets assigned would see nothing at all.
                </p>

                <div class="mt-3 space-y-2">
                    <label v-for="outlet in outlets.outlets" :key="outlet.id" class="flex items-center gap-3 text-sm">
                        <input v-model="form.outlet_ids" type="checkbox" :value="outlet.id" class="accent-amber-900" />
                        {{ outlet.name }}
                    </label>
                </div>
            </fieldset>

            <div class="flex justify-end">
                <button
                    type="submit"
                    class="rounded-lg bg-amber-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                    :disabled="saving"
                >
                    {{ saving ? 'Creating…' : 'Create account' }}
                </button>
            </div>
        </form>

        <div class="mt-4 space-y-2">
            <div
                v-for="user in users"
                :key="user.id"
                class="flex flex-wrap items-center gap-4 rounded-xl border border-stone-200 bg-white p-4"
            >
                <div class="min-w-0 flex-1">
                    <p class="truncate font-medium text-stone-900">
                        {{ user.name }}
                        <span v-if="user.id === auth.user?.id" class="ml-1 rounded bg-stone-200 px-1.5 py-0.5 text-[10px]">
                            you
                        </span>
                        <span v-if="!user.is_active" class="ml-1 rounded bg-stone-200 px-1.5 py-0.5 text-[10px]">
                            deactivated
                        </span>
                    </p>
                    <p class="truncate text-xs text-stone-500">
                        {{ user.email }} · {{ user.role_label }} · {{ outletNames(user) }}
                    </p>
                </div>

                <div class="flex shrink-0 items-center gap-2 text-xs">
                    <RouterLink
                        :to="{ name: 'employees' }"
                        v-if="user.employee_id"
                        class="rounded-lg border border-stone-300 px-2.5 py-1.5"
                    >
                        Linked: {{ user.employee_name }}
                    </RouterLink>

                    <button
                        type="button"
                        class="rounded-lg border px-2.5 py-1.5 font-medium"
                        :class="user.is_active ? 'border-red-300 text-red-700' : 'border-emerald-300 text-emerald-800'"
                        @click="toggleActive(user)"
                    >
                        {{ user.is_active ? 'Deactivate' : 'Reactivate' }}
                    </button>
                </div>
            </div>
        </div>

        <p class="mt-6 rounded-xl border border-stone-200 bg-white px-4 py-3 text-xs text-stone-500">
            Staff clock in from their own phone without signing in — an account here is
            only needed for the management screens. Single sign-on with the table
            ordering system is designed but not yet wired up; see
            <code>docs/sso.md</code>.
        </p>
    </div>
</template>
