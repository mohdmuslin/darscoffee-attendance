<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';

const props = defineProps({
    /** The action this photo is for, used for the heading and the confirm wording. */
    action: { type: String, required: true },

    /** A server-side rejection, shown inside the sheet rather than behind it. */
    error: { type: String, default: null },

    /** Ask for an explicit acknowledgement before submitting. */
    confirming: { type: Boolean, default: false },
});

const emit = defineEmits(['captured', 'cancel']);

const label = computed(() => ({
    clock_in: 'Clock in',
    start_break: 'Start break',
    end_break: 'End break',
    clock_out: 'Clock out',
})[props.action] ?? 'Continue');

const video = ref(null);
const captured = ref(null);
const ready = ref(false);
const cameraError = ref(null);
const acknowledged = ref(false);

let stream = null;

onMounted(async () => {
    try {
        stream = await navigator.mediaDevices.getUserMedia({
            // Front camera: this is a selfie to confirm who is on shift.
            video: { facingMode: 'user', width: { ideal: 1280 } },
        });

        if (video.value) {
            video.value.srcObject = stream;
            await video.value.play();
            ready.value = true;
        }
    } catch (error) {
        cameraError.value = error?.name === 'NotAllowedError'
            ? 'Camera access was blocked. Allow it for this site, then try again.'
            : 'Could not open the camera.';
    }
});

onUnmounted(stop);

function stop() {
    if (stream) {
        stream.getTracks().forEach((track) => track.stop());
        stream = null;
    }
}

/**
 * Capture a frame from the live video onto a canvas.
 *
 * Scaled down and re-encoded as JPEG. A raw phone frame is several megabytes and
 * would exceed the server's 3 MB cap, and the point of the photo is to recognise a
 * face — not to preserve sensor detail.
 *
 * The video is scaled, never cropped or distorted, because it is evidence.
 */
function capture() {
    const source = video.value;

    if (! source || ! source.videoWidth) {
        return;
    }

    const maxWidth = 720;
    const scale = Math.min(1, maxWidth / source.videoWidth);

    const canvas = document.createElement('canvas');
    canvas.width = Math.round(source.videoWidth * scale);
    canvas.height = Math.round(source.videoHeight * scale);

    const context = canvas.getContext('2d');
    context.drawImage(source, 0, 0, canvas.width, canvas.height);

    captured.value = canvas.toDataURL('image/jpeg', 0.7);

    // The camera is released now: holding it open while the employee decides wastes
    // battery, and the preview is frozen on the captured frame anyway.
    stop();
}

function retake() {
    captured.value = null;
    acknowledged.value = false;
}

function confirm() {
    if (props.confirming && ! acknowledged.value) {
        return;
    }

    emit('captured', captured.value);
}

const canSubmit = computed(() => Boolean(captured.value)
    && (! props.confirming || acknowledged.value));
</script>

<template>
    <div class="fixed inset-0 z-50 flex flex-col bg-stone-900/95 px-4 py-6">
        <div class="mx-auto flex w-full max-w-md flex-1 flex-col">
            <p class="text-center text-sm text-stone-300">
                {{ captured ? 'Check your photo' : `Photo for: ${label}` }}
            </p>

            <div class="mt-4 flex-1 overflow-hidden rounded-2xl bg-black">
                <img
                    v-if="captured"
                    :src="captured"
                    alt="Your punch photo"
                    class="h-full w-full object-contain"
                />
                <video
                    v-else
                    ref="video"
                    class="h-full w-full object-cover"
                    playsinline
                    muted
                ></video>
            </div>

            <p v-if="cameraError" class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">
                {{ cameraError }}
            </p>

            <p v-if="error" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
                {{ error }}
            </p>

            <label
                v-if="confirming && captured"
                class="mt-4 flex items-start gap-3 rounded-xl bg-stone-800 px-4 py-3 text-sm text-stone-100"
            >
                <input v-model="acknowledged" type="checkbox" class="mt-0.5" />
                <span>Yes, I am clocking out for today.</span>
            </label>

            <div class="mt-4 space-y-2 pb-2">
                <button
                    v-if="! captured"
                    type="button"
                    class="w-full rounded-2xl bg-white py-4 text-base font-semibold text-stone-900 disabled:opacity-40"
                    :disabled="! ready"
                    @click="capture"
                >
                    {{ ready ? 'Take photo' : 'Starting camera…' }}
                </button>

                <template v-else>
                    <button
                        type="button"
                        class="w-full rounded-2xl bg-amber-900 py-4 text-base font-semibold text-white disabled:opacity-40"
                        :disabled="! canSubmit"
                        @click="confirm"
                    >
                        Confirm and {{ label.toLowerCase() }}
                    </button>

                    <button
                        type="button"
                        class="w-full rounded-2xl border border-stone-600 py-3 text-sm font-medium text-stone-200"
                        @click="retake"
                    >
                        Retake
                    </button>
                </template>

                <button
                    type="button"
                    class="w-full py-3 text-sm text-stone-400"
                    @click="emit('cancel')"
                >
                    Cancel
                </button>
            </div>
        </div>
    </div>
</template>
