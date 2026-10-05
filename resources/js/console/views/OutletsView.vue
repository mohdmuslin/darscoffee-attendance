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

/*
 * The outlet being edited, or null when adding a new one. One form serves both, because
 * the fields are identical and two screens would drift apart the first time a field was
 * added to only one of them.
 */
const editing = ref(null);

const CODE_LIFETIMES = [
    { seconds: 60, label: '1 minute' },
    { seconds: 90, label: '90 seconds (default)' },
    { seconds: 300, label: '5 minutes' },
    { seconds: 600, label: '10 minutes' },
    { seconds: 3600, label: '1 hour' },
    { seconds: 21600, label: '6 hours' },
    { seconds: 86400, label: '24 hours' },
];

const blankForm = () => ({
    code: '',
    name: '',
    address: '',
    token_mode: 'rotating',
    qr_ttl_seconds: 90,
    requires_photo: true,
});

const form = reactive(blankForm());

onMounted(() => outlets.load());

function startAdding() {
    editing.value = null;
    Object.assign(form, blankForm());
    error.value = null;
    showForm.value = true;
}

function startEditing(outlet) {
    editing.value = outlet.id;
    Object.assign(form, {
        code: outlet.code,
        name: outlet.name,
        address: outlet.address ?? '',
        token_mode: outlet.token_mode,
        qr_ttl_seconds: outlet.qr_ttl_seconds,
        requires_photo: outlet.requires_photo,
    });
    error.value = null;
    showForm.value = true;
}

function cancel() {
    showForm.value = false;
    editing.value = null;
    error.value = null;
}

async function save() {
    saving.value = true;
    error.value = null;

    try {
        if (editing.value === null) {
            await outlets.create({ ...form });
        } else {
            /*
             * `code` is omitted when editing. Changing an outlet's short code would
             * orphan any printed sheet already on the wall, and the server treats it as
             * unique — so the field is shown read-only rather than being sent.
             */
            const { code, ...payload } = form;
            await outlets.update(editing.value, payload);
        }

        cancel();
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
                @click="showForm ? cancel() : startAdding()"
            >
                {{ showForm ? 'Cancel' : 'Add outlet' }}
            </button>
        </div>

        <form
            v-if="showForm"
            class="mt-4 space-y-4 rounded-xl border border-stone-200 bg-white p-4"
            @submit.prevent="save"
        >
            <p class="text-sm font-medium text-stone-900">
                {{ editing === null ? 'Add an outlet' : 'Edit this outlet' }}
            </p>

            <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="text-sm font-medium text-stone-700">
                    Short code
                    <input
                        v-model="form.code"
                        :required="editing === null"
                        :readonly="editing !== null"
                        placeholder="SG-RAMAL"
                        class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 read-only:bg-stone-100 read-only:text-stone-500"
                    />
                    <span v-if="editing !== null" class="mt-1 block text-xs text-stone-400">
                        Cannot be changed — a printed sheet already carries this code.
                    </span>
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
                        Rotating is stronger: a photograph of it goes stale.
                    </span>
                </label>

                <!--
                    The lifetime only applies to a ROTATING code. A printed sheet has no
                    expiry at all — it stays valid until it is reprinted — so showing an
                    inert field there would invite people to set it and wonder why
                    nothing happened.
                -->
                <label v-if="form.token_mode === 'rotating'" class="text-sm font-medium text-stone-700">
                    Code expires after
                    <select
                        v-model.number="form.qr_ttl_seconds"
                        class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2"
                    >
                        <option v-for="option in CODE_LIFETIMES" :key="option.seconds" :value="option.seconds">
                            {{ option.label }}
                        </option>
                    </select>
                    <span class="mt-1 block text-xs text-stone-400">
                        How long a code stays usable after the device shows it.
                    </span>
                </label>

                <p v-else class="self-end rounded-lg bg-stone-50 px-3 py-2 text-xs text-stone-500">
                    A printed sheet never expires. It stays valid until you reprint it in
                    <strong>Codes &amp; printing</strong>, which is also how you revoke it
                    if it leaks.
                </p>

                <label class="flex items-center gap-2 self-end text-sm font-medium text-stone-700">
                    <input v-model="form.requires_photo" type="checkbox" class="accent-amber-900" />
                    Require a photo on every punch
                </label>
            </div>

            <!--
                A long rotating window is a real security trade, so it is stated at the
                point of choosing rather than buried in a manual. A code photographed on
                Monday that still works on Sunday is effectively a printed sheet, and
                saying so is better than a silent downgrade nobody notices.
            -->
            <p
                v-if="form.token_mode === 'rotating' && form.qr_ttl_seconds > 600"
                class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900"
            >
                <strong>A window this long weakens the rotating code.</strong>
                If someone photographs the code it will keep working until it expires — for
                {{ Math.round(form.qr_ttl_seconds / 3600) }} hour(s). If you need a code that lasts
                that long, <em>Printed</em> is the honest choice: it says outright that the code is
                valid until reprinted, instead of implying it is short-lived.
            </p>

            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    class="rounded-lg border border-stone-300 px-4 py-2 text-sm font-medium text-stone-700"
                    @click="cancel"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    class="rounded-lg bg-amber-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                    :disabled="saving"
                >
                    {{ saving ? 'Saving…' : (editing === null ? 'Add outlet' : 'Save changes') }}
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
                        <p v-if="outlet.token_mode === 'rotating'" class="text-xs text-stone-400">
                            code expires after {{ outlet.qr_ttl_seconds }}s
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

                        <!--
                            Editing is owner-only, matching the server. A manager who could
                            see the button but be refused would waste a trip.
                        -->
                        <button
                            v-if="auth.isOwner"
                            type="button"
                            class="rounded-lg border border-stone-300 px-2.5 py-1.5 font-medium"
                            @click="startEditing(outlet)"
                        >
                            Edit
                        </button>

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
