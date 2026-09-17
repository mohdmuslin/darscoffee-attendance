<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { employeeApi } from '../services/api';
import { useEmployeeStore } from '../stores/employees';
import { useOutletStore } from '../stores/outlets';

const props = defineProps({
    id: { type: [String, Number], default: null },
});

const router = useRouter();
const employees = useEmployeeStore();
const outlets = useOutletStore();

const isNew = computed(() => props.id === null || props.id === undefined);

const form = reactive({
    employee_code: '',
    name: '',
    phone: '',
    ic_number: '',
    pay_basis: '',
    joined_at: '',
    outlet_ids: [],
    primary_outlet_id: null,
    is_active: true,
    pin: '',
});

const loading = ref(false);
const saving = ref(false);
const errors = ref({});
const photoFile = ref(null);
const photoPreview = ref(null);
const currentPhotoUrl = ref(null);

onMounted(async () => {
    await outlets.load();

    if (isNew.value) {
        // Preselect the only outlet a manager can see, so the common case is one click.
        if (outlets.outlets.length === 1) {
            form.outlet_ids = [outlets.outlets[0].id];
            form.primary_outlet_id = outlets.outlets[0].id;
        }

        return;
    }

    loading.value = true;

    try {
        const employee = await employeeApi.get(props.id);

        Object.assign(form, {
            employee_code: employee.employee_code,
            name: employee.name,
            phone: employee.phone ?? '',
            /*
             * Left blank deliberately. The API returns a MASKED IC number, so there is
             * nothing meaningful to prefill — and prefilling asterisks would save them
             * back as the real value on the next save.
             */
            ic_number: '',
            pay_basis: employee.pay_basis ?? '',
            joined_at: employee.joined_at ?? '',
            outlet_ids: employee.outlets.map((outlet) => outlet.id),
            primary_outlet_id: employee.outlets.find((outlet) => outlet.is_primary)?.id
                ?? employee.outlets[0]?.id
                ?? null,
            is_active: employee.is_active,
        });

        currentPhotoUrl.value = employee.photo_url;
    } catch (error) {
        errors.value = { general: error.message };
    } finally {
        loading.value = false;
    }
});

function onPhotoChange(event) {
    const file = event.target.files?.[0] ?? null;

    photoFile.value = file;
    photoPreview.value = file ? URL.createObjectURL(file) : null;
}

async function submit() {
    saving.value = true;
    errors.value = {};

    try {
        const payload = {
            employee_code: form.employee_code,
            name: form.name,
            phone: form.phone || null,
            pay_basis: form.pay_basis || null,
            joined_at: form.joined_at || null,
            outlet_ids: form.outlet_ids,
            primary_outlet_id: form.primary_outlet_id,
            is_active: form.is_active,
        };

        /*
         * The IC number is only sent when provided. Sending an empty string would
         * clear a stored value, which is not what an untouched field means.
         */
        if (form.ic_number !== '') {
            payload.ic_number = form.ic_number;
        }

        if (isNew.value && form.pin !== '') {
            payload.pin = form.pin;
        }

        const employee = await employees.save(isNew.value ? null : props.id, payload);

        if (photoFile.value) {
            await employees.uploadPhoto(employee.id, photoFile.value);
        }

        router.push({ name: 'employees' });
    } catch (error) {
        // Field errors come back as { field: [messages] }, which is what the form needs.
        errors.value = error.fieldErrors ?? { general: error.message };
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="mx-auto max-w-2xl">
        <h1 class="text-lg font-semibold text-stone-900">
            {{ isNew ? 'Add employee' : 'Edit employee' }}
        </h1>

        <p v-if="loading" class="mt-4 text-sm text-stone-500">Loading…</p>

        <form v-else class="mt-4 space-y-5" @submit.prevent="submit">
            <p v-if="errors.general" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ errors.general }}
            </p>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="text-sm font-medium text-stone-700">
                    Staff number
                    <input
                        v-model="form.employee_code"
                        required
                        class="mt-1 block w-full rounded-lg border px-3 py-2"
                        :class="errors.employee_code ? 'border-red-400' : 'border-stone-300'"
                    />
                    <span v-if="errors.employee_code" class="mt-1 block text-xs text-red-600">
                        {{ errors.employee_code[0] }}
                    </span>
                </label>

                <label class="text-sm font-medium text-stone-700">
                    Full name
                    <input
                        v-model="form.name"
                        required
                        class="mt-1 block w-full rounded-lg border px-3 py-2"
                        :class="errors.name ? 'border-red-400' : 'border-stone-300'"
                    />
                    <span v-if="errors.name" class="mt-1 block text-xs text-red-600">
                        {{ errors.name[0] }}
                    </span>
                </label>

                <label class="text-sm font-medium text-stone-700">
                    Phone
                    <input
                        v-model="form.phone"
                        class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2"
                    />
                </label>

                <label class="text-sm font-medium text-stone-700">
                    IC number
                    <input
                        v-model="form.ic_number"
                        placeholder="900101-14-5566"
                        class="mt-1 block w-full rounded-lg border px-3 py-2"
                        :class="errors.ic_number ? 'border-red-400' : 'border-stone-300'"
                    />
                    <span v-if="errors.ic_number" class="mt-1 block text-xs text-red-600">
                        {{ errors.ic_number[0] }}
                    </span>
                    <span v-else class="mt-1 block text-xs text-stone-400">
                        <template v-if="isNew">Stored encrypted, shown masked afterwards.</template>
                        <template v-else>
                            Leave blank to keep the stored number. It is never shown in full.
                        </template>
                    </span>
                </label>

                <label class="text-sm font-medium text-stone-700">
                    Pay basis
                    <select
                        v-model="form.pay_basis"
                        class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2"
                    >
                        <option value="">Not set</option>
                        <option value="hourly">Per hour</option>
                        <option value="daily">Per day</option>
                        <option value="weekly">Per week</option>
                        <option value="monthly">Per month</option>
                    </select>
                </label>

                <label class="text-sm font-medium text-stone-700">
                    Joined
                    <input
                        v-model="form.joined_at"
                        type="date"
                        class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2"
                    />
                </label>
            </div>

            <fieldset class="rounded-xl border border-stone-200 bg-white p-4">
                <legend class="px-1 text-sm font-medium text-stone-700">Outlets</legend>

                <p class="text-xs text-stone-500">
                    A punch is only accepted at an outlet the employee is mapped to.
                </p>

                <div class="mt-3 space-y-2">
                    <label
                        v-for="outlet in outlets.outlets"
                        :key="outlet.id"
                        class="flex items-center gap-3 text-sm"
                    >
                        <input
                            v-model="form.outlet_ids"
                            type="checkbox"
                            :value="outlet.id"
                            class="accent-amber-900"
                        />
                        <span>{{ outlet.name }}</span>

                        <span class="ml-auto flex items-center gap-1 text-xs text-stone-500">
                            <input
                                v-model="form.primary_outlet_id"
                                type="radio"
                                :value="outlet.id"
                                :disabled="!form.outlet_ids.includes(outlet.id)"
                                class="accent-amber-900"
                            />
                            main
                        </span>
                    </label>
                </div>

                <p v-if="errors.outlet_ids" class="mt-2 text-xs text-red-600">
                    {{ errors.outlet_ids[0] }}
                </p>
            </fieldset>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="text-sm font-medium text-stone-700">
                    {{ isNew ? 'PIN (optional now)' : 'Photo' }}
                    <template v-if="isNew">
                        <input
                            v-model="form.pin"
                            inputmode="numeric"
                            maxlength="6"
                            placeholder="4 to 6 digits"
                            class="mt-1 block w-full rounded-lg border px-3 py-2"
                            :class="errors.pin ? 'border-red-400' : 'border-stone-300'"
                        />
                        <span class="mt-1 block text-xs text-stone-400">
                            Can be set later. Without a PIN they cannot clock in.
                        </span>
                    </template>
                </label>

                <div class="text-sm font-medium text-stone-700">
                    Photo
                    <div class="mt-1 flex items-center gap-3">
                        <img
                            v-if="photoPreview || currentPhotoUrl"
                            :src="photoPreview || currentPhotoUrl"
                            alt=""
                            class="h-14 w-14 rounded-full object-cover"
                        />
                        <input
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            class="block w-full text-xs"
                            @change="onPhotoChange"
                        />
                    </div>
                </div>
            </div>

            <label class="flex items-center gap-2 text-sm font-medium text-stone-700">
                <input v-model="form.is_active" type="checkbox" class="accent-amber-900" />
                Active (can clock in)
            </label>

            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    class="rounded-lg border border-stone-300 px-4 py-2 text-sm"
                    @click="router.push({ name: 'employees' })"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    class="rounded-lg bg-amber-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                    :disabled="saving"
                >
                    {{ saving ? 'Saving…' : 'Save' }}
                </button>
            </div>
        </form>
    </div>
</template>
