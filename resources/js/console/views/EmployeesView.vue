<script setup>
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { useEmployeeStore } from '../stores/employees';
import { useOutletStore } from '../stores/outlets';

const auth = useAuthStore();
const employees = useEmployeeStore();
const outlets = useOutletStore();

const busyId = ref(null);
const pinDialog = ref(null);
const pinValue = ref('');

onMounted(async () => {
    await outlets.load();
    await employees.load();
});

/** Outlets this user may pick from — already scoped by the server. */
const pickableOutlets = computed(() => outlets.outlets);

function canSeeOutletName(employee) {
    return employee.outlets.map((outlet) => outlet.name).join(', ');
}

async function toggleActive(employee) {
    const verb = employee.is_active ? 'Deactivate' : 'Reactivate';

    if (! window.confirm(
        `${verb} ${employee.name}?\n\n`
        + (employee.is_active
            ? 'Their history is kept, and they can no longer clock in.'
            : 'They will be able to clock in again.'),
    )) {
        return;
    }

    busyId.value = employee.id;

    try {
        await employees.toggleActive(employee);
    } catch (error) {
        window.alert(error.message);
    } finally {
        busyId.value = null;
    }
}

function openPinDialog(employee) {
    pinDialog.value = employee;
    pinValue.value = '';
}

async function savePin() {
    if (! /^\d{4,6}$/.test(pinValue.value)) {
        window.alert('The PIN must be 4 to 6 digits.');

        return;
    }

    busyId.value = pinDialog.value.id;

    try {
        await employees.setPin(pinDialog.value.id, pinValue.value);
        pinDialog.value = null;
        pinValue.value = '';
    } catch (error) {
        window.alert(error.message);
    } finally {
        busyId.value = null;
    }
}

async function clearPin(employee) {
    /*
     * Stated plainly, because the consequence is not obvious: an employee with no PIN
     * cannot clock in at all, and that will look like a broken system to whoever is
     * standing at the counter at 7am.
     */
    if (! window.confirm(
        `Clear the PIN for ${employee.name}?\n\n`
        + 'They will NOT be able to clock in until a new PIN is set.',
    )) {
        return;
    }

    busyId.value = employee.id;

    try {
        await employees.clearPin(employee.id);
    } catch (error) {
        window.alert(error.message);
    } finally {
        busyId.value = null;
    }
}
</script>

<template>
    <div>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold text-stone-900">Employees</h1>
                <p class="text-xs text-stone-500">
                    <template v-if="auth.isOwner">
                        Everyone across all outlets.
                    </template>
                    <template v-else>
                        Staff at your outlet(s) only.
                    </template>
                </p>
            </div>

            <RouterLink
                :to="{ name: 'employee-new' }"
                class="rounded-lg bg-amber-900 px-3 py-2 text-sm font-medium text-white"
            >
                Add employee
            </RouterLink>
        </div>

        <div class="mt-4 flex flex-wrap items-end gap-3 rounded-xl border border-stone-200 bg-white p-4">
            <label class="text-sm font-medium">
                Search
                <input
                    v-model="employees.filters.search"
                    type="search"
                    placeholder="name or staff number"
                    class="mt-1 block rounded-lg border border-stone-300 px-3 py-2"
                    @keyup.enter="employees.load()"
                />
            </label>

            <label class="text-sm font-medium">
                Outlet
                <!--
                    Only outlets this user may see are offered. The server scopes the
                    query anyway, so this is about not offering a filter that silently
                    does nothing.
                -->
                <select
                    v-model="employees.filters.outlet_id"
                    class="mt-1 block rounded-lg border border-stone-300 px-3 py-2"
                    @change="employees.load()"
                >
                    <option value="">All my outlets</option>
                    <option v-for="outlet in pickableOutlets" :key="outlet.id" :value="outlet.id">
                        {{ outlet.name }}
                    </option>
                </select>
            </label>

            <label class="flex items-center gap-2 text-sm font-medium">
                <input
                    v-model="employees.filters.include_inactive"
                    type="checkbox"
                    class="accent-amber-900"
                    @change="employees.load()"
                />
                Include former staff
            </label>

            <button
                type="button"
                class="ml-auto rounded-lg border border-stone-300 px-3 py-2 text-sm font-medium"
                @click="employees.load()"
            >
                Search
            </button>
        </div>

        <p v-if="employees.error" class="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ employees.error }}
        </p>

        <p
            v-if="!employees.loading && employees.employees.length === 0"
            class="mt-4 rounded-xl border border-stone-200 bg-white px-4 py-8 text-center text-sm text-stone-500"
        >
            No employees{{ employees.filters.search ? ' match that search' : ' yet' }}.
        </p>

        <div class="mt-4 space-y-2">
            <div
                v-for="employee in employees.employees"
                :key="employee.id"
                class="flex flex-wrap items-center gap-4 rounded-xl border border-stone-200 bg-white p-4"
            >
                <img
                    v-if="employee.photo_url"
                    :src="employee.photo_url"
                    :alt="employee.name"
                    class="h-12 w-12 rounded-full object-cover"
                />
                <div
                    v-else
                    class="flex h-12 w-12 items-center justify-center rounded-full bg-stone-100 text-xs text-stone-400"
                >
                    n/a
                </div>

                <div class="min-w-0 flex-1">
                    <p class="truncate font-medium text-stone-900">
                        {{ employee.name }}
                        <span v-if="!employee.is_active" class="ml-1 rounded bg-stone-200 px-1.5 py-0.5 text-[10px]">
                            former
                        </span>
                    </p>
                    <p class="truncate text-xs text-stone-500">
                        {{ employee.employee_code }}
                        · {{ canSeeOutletName(employee) }}
                        <span v-if="employee.pay_basis"> · {{ employee.pay_basis_label }}</span>
                    </p>
                </div>

                <div class="flex shrink-0 flex-wrap items-center gap-2 text-xs">
                    <!--
                        PIN state is shown prominently because an employee without one
                        simply cannot clock in — which reads as a broken system at the
                        counter unless someone can see the cause.
                    -->
                    <span
                        v-if="!employee.has_pin"
                        class="rounded-full bg-amber-100 px-2 py-1 font-medium text-amber-900"
                    >
                        no PIN — cannot clock in
                    </span>
                    <span
                        v-else-if="employee.pin_locked"
                        class="rounded-full bg-red-100 px-2 py-1 font-medium text-red-800"
                    >
                        PIN locked
                    </span>
                    <span v-else class="rounded-full bg-emerald-100 px-2 py-1 text-emerald-800">
                        PIN set
                    </span>

                    <button
                        type="button"
                        class="rounded-lg border border-stone-300 px-2.5 py-1.5 font-medium disabled:opacity-40"
                        :disabled="busyId === employee.id"
                        @click="openPinDialog(employee)"
                    >
                        Set PIN
                    </button>

                    <button
                        v-if="employee.has_pin"
                        type="button"
                        class="rounded-lg border border-stone-300 px-2.5 py-1.5 font-medium disabled:opacity-40"
                        :disabled="busyId === employee.id"
                        @click="clearPin(employee)"
                    >
                        Clear
                    </button>

                    <RouterLink
                        :to="{ name: 'employee-edit', params: { id: employee.id } }"
                        class="rounded-lg border border-stone-300 px-2.5 py-1.5 font-medium"
                    >
                        Edit
                    </RouterLink>

                    <button
                        type="button"
                        class="rounded-lg border px-2.5 py-1.5 font-medium disabled:opacity-40"
                        :class="employee.is_active ? 'border-red-300 text-red-700' : 'border-emerald-300 text-emerald-800'"
                        :disabled="busyId === employee.id"
                        @click="toggleActive(employee)"
                    >
                        {{ employee.is_active ? 'Deactivate' : 'Reactivate' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Set-PIN dialog. A plain overlay rather than a library: one input does not
             justify a dependency. -->
        <div
            v-if="pinDialog"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4"
            @click.self="pinDialog = null"
        >
            <div class="w-full max-w-sm rounded-2xl bg-white p-6">
                <h2 class="font-semibold text-stone-900">
                    Set a PIN for {{ pinDialog.name }}
                </h2>
                <p class="mt-1 text-xs text-stone-500">
                    4 to 6 digits. They will use this with the outlet code to clock in.
                </p>

                <input
                    v-model="pinValue"
                    type="text"
                    inputmode="numeric"
                    maxlength="6"
                    autofocus
                    class="mt-4 block w-full rounded-lg border border-stone-300 px-3 py-3 text-center text-2xl tracking-widest"
                    @keyup.enter="savePin"
                />

                <div class="mt-4 flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-lg border border-stone-300 px-3 py-2 text-sm"
                        @click="pinDialog = null"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="rounded-lg bg-amber-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                        :disabled="busyId === pinDialog.id"
                        @click="savePin"
                    >
                        Save PIN
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
