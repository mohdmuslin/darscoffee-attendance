<script setup>
import { onMounted, onUnmounted, ref } from 'vue';

const emit = defineEmits(['scanned']);

const scanning = ref(false);
const cameraError = ref(null);
const manualToken = ref('');
const video = ref(null);

let stream = null;
let detector = null;
let frameHandle = null;

/*
 * Camera access requires HTTPS (or localhost). On plain HTTP the browser refuses
 * silently, so the manual entry fallback below is not a nicety — it is the only way
 * this screen works during local testing.
 */
const cameraAvailable = typeof navigator !== 'undefined'
    && !!navigator.mediaDevices?.getUserMedia;

onMounted(async () => {
    if (! cameraAvailable) {
        cameraError.value = 'This browser cannot open the camera. Ask a manager for the code and type it below.';

        return;
    }

    /*
     * BarcodeDetector is not in every browser, notably older iOS Safari. Where it is
     * missing the scanner simply does not start and the fallback is offered — better
     * than a spinner and no explanation.
     */
    if (! ('BarcodeDetector' in window)) {
        cameraError.value = 'This browser cannot scan QR codes. Ask a manager for the code and type it below.';

        return;
    }

    await start();
});

onUnmounted(stop);

async function start() {
    scanning.value = true;
    cameraError.value = null;

    try {
        stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'environment' },
        });

        if (video.value) {
            video.value.srcObject = stream;
            await video.value.play();
        }

        detector = new window.BarcodeDetector({ formats: ['qr_code'] });
        tick();
    } catch (error) {
        scanning.value = false;
        cameraError.value = error?.name === 'NotAllowedError'
            ? 'Camera access was blocked. Allow it in your browser settings, or type the code below.'
            : 'Could not open the camera. Type the code below instead.';
    }
}

/**
 * Poll frames rather than using a continuous detector API.
 *
 * `BarcodeDetector.detect()` is one-shot, so scanning is a loop. It is throttled to
 * roughly four times a second — a phone held steady finds the code in that time, and
 * a tighter loop would heat the device and drain the battery during a long queue.
 */
function tick() {
    frameHandle = window.setTimeout(async () => {
        if (detector && video.value && video.value.readyState === video.value.HAVE_ENOUGH_DATA) {
            try {
                const codes = await detector.detect(video.value);

                if (codes.length > 0) {
                    const value = codes[0].rawValue;
                    stop();
                    emit('scanned', extractToken(value));

                    return;
                }
            } catch {
                // A transient decode failure is normal between frames; keep going.
            }
        }

        tick();
    }, 250);
}

function stop() {
    scanning.value = false;

    if (frameHandle) {
        window.clearTimeout(frameHandle);
        frameHandle = null;
    }

    if (stream) {
        stream.getTracks().forEach((track) => track.stop());
        stream = null;
    }
}

/**
 * Accept either a full scan URL or a bare code.
 *
 * The QR encodes the whole `/punch?t=<token>` URL, but someone reading a code off a
 * sheet might type only the token — both should work rather than making them guess.
 */
function extractToken(value) {
    const trimmed = (value ?? '').trim();
    const match = trimmed.match(/[?&]t=([^&\s]+)/);

    return match ? decodeURIComponent(match[1]) : trimmed;
}

function submitManual() {
    const token = extractToken(manualToken.value);

    if (token !== '') {
        emit('scanned', token);
    }
}
</script>

<template>
    <div class="flex flex-1 flex-col">
        <div class="relative overflow-hidden rounded-2xl border border-stone-200 bg-black">
            <video
                ref="video"
                class="h-72 w-full object-cover"
                playsinline
                muted
            ></video>

            <!-- A framing guide, because the code has to be inside the view for the
                 detector to read it and people otherwise hold the phone too far away. -->
            <div class="pointer-events-none absolute inset-0 flex items-center justify-center">
                <div class="h-48 w-48 rounded-xl border-2 border-white/80"></div>
            </div>
        </div>

        <p class="mt-4 text-center text-sm text-stone-600">
            Point your phone at the code at the counter.
        </p>

        <p v-if="cameraError" class="mt-3 rounded-lg bg-amber-50 px-4 py-3 text-xs text-amber-900">
            {{ cameraError }}
        </p>

        <!--
            Always available, not only on error. The printer being out of paper or a
            greasy lens is exactly when this matters, and discovering the fallback only
            after the camera fails wastes a queue.
        -->
        <details class="mt-4 rounded-xl border border-stone-200 bg-white px-4 py-3">
            <summary class="cursor-pointer text-sm text-stone-600">
                Can't scan? Type the code instead
            </summary>

            <div class="mt-3 space-y-2">
                <input
                    v-model="manualToken"
                    type="text"
                    placeholder="Paste or type the code"
                    class="block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
                    @keyup.enter="submitManual"
                />
                <button
                    type="button"
                    class="w-full rounded-lg bg-amber-900 px-4 py-2.5 text-sm font-medium text-white"
                    @click="submitManual"
                >
                    Continue
                </button>
            </div>
        </details>
    </div>
</template>
