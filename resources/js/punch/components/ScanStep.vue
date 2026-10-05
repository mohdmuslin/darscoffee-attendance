<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import jsQR from 'jsqr';

const emit = defineEmits(['scanned']);

const scanning = ref(false);
const cameraError = ref(null);
const manualToken = ref('');
const video = ref(null);
const canvas = ref(null);

let stream = null;
let frameHandle = null;
let detector = null;

/*
 * Camera access requires HTTPS (or localhost). On plain HTTP the browser refuses
 * silently, so the manual entry fallback below is not a nicety — it is the only way
 * this screen works during local testing.
 */
const cameraAvailable = typeof navigator !== 'undefined'
    && !!navigator.mediaDevices?.getUserMedia;

/*
 * `BarcodeDetector` is used when it exists, because on Android it is hardware accelerated
 * and much faster than decoding in JavaScript.
 *
 * It is strictly an OPTIMISATION, never a requirement. It does not exist on ANY iPhone —
 * not old ones, any of them — because iOS Safari and iOS WebView do not implement the
 * Barcode Detection API. An earlier version of this component REQUIRED it and returned
 * before opening the camera, so every iPhone showed a black rectangle and "This browser
 * cannot scan QR codes".
 *
 * jsQR is the fallback and works everywhere, so the screen now degrades in speed rather
 * than in availability.
 */
const hasNativeDetector = typeof window !== 'undefined' && 'BarcodeDetector' in window;

onMounted(async () => {
    if (! cameraAvailable) {
        cameraError.value = 'This browser cannot open the camera. Ask a manager for the code and type it below.';

        return;
    }

    await start();
});

onUnmounted(stop);

async function start() {
    scanning.value = true;
    cameraError.value = null;

    try {
        /*
         * A moderate resolution. A 1280px-wide frame is plenty for a code held at arm's
         * length, and it keeps each decode cheap — a 4K frame is many times the work for
         * no gain, and on a phone that heat is felt during a queue.
         */
        stream = await navigator.mediaDevices.getUserMedia({
            video: {
                facingMode: { ideal: 'environment' },
                width: { ideal: 1280 },
                height: { ideal: 720 },
            },
        });

        if (video.value) {
            video.value.srcObject = stream;
            await video.value.play();
        }

        if (hasNativeDetector) {
            try {
                detector = new window.BarcodeDetector({ formats: ['qr_code'] });
            } catch {
                // Construction can fail where the API exists but supports no formats.
                detector = null;
            }
        }

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
 * Both paths are one-shot, so scanning is a loop. It is throttled to roughly four times a
 * second — a phone held steady finds the code in that time, and a tighter loop would heat
 * the device and drain the battery during a long queue.
 */
function tick() {
    frameHandle = window.setTimeout(async () => {
        const value = await readFrame();

        if (value !== null) {
            stop();
            emit('scanned', extractToken(value));

            return;
        }

        tick();
    }, 250);
}

/** One decode attempt. Returns the raw code, or null if this frame yielded nothing. */
async function readFrame() {
    const source = video.value;

    if (! source || source.readyState !== source.HAVE_ENOUGH_DATA) {
        return null;
    }

    try {
        if (detector) {
            const codes = await detector.detect(source);

            return codes.length > 0 ? codes[0].rawValue : null;
        }

        return readFrameWithJsQr(source);
    } catch {
        // A transient decode failure is normal between frames; keep going.
        return null;
    }
}

/**
 * Decode a frame in JavaScript via jsQR.
 *
 * The frame is drawn to an offscreen canvas because jsQR needs raw pixel data, which only
 * the canvas can provide — a `<video>` cannot be read directly.
 *
 * The canvas is REUSED across frames. Creating one per frame at four frames a second would
 * allocate and discard roughly a megabyte of bitmap ten times a second, which is exactly
 * the churn that makes a cheap phone stutter.
 */
function readFrameWithJsQr(source) {
    const surface = canvas.value;

    if (! surface) {
        return null;
    }

    const width = source.videoWidth;
    const height = source.videoHeight;

    if (! width || ! height) {
        return null;
    }

    if (surface.width !== width || surface.height !== height) {
        surface.width = width;
        surface.height = height;
    }

    const context = surface.getContext('2d', { willReadFrequently: true });
    context.drawImage(source, 0, 0, width, height);

    const image = context.getImageData(0, 0, width, height);
    const result = jsQR(image.data, image.width, image.height, {
        /*
         * The code is printed on paper and photographed by a phone, so it is never
         * mirrored. Leaving inversion on would double the decode work for a case that
         * cannot occur here.
         */
        inversionAttempts: 'dontInvert',
    });

    return result ? result.data : null;
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
        <!--
            The camera frame is sized by ASPECT RATIO, never a fixed height.

            It used to be `h-72 w-full object-cover` — 288px tall across the full width. On
            a narrow phone that box is TALLER than it is wide, so the camera's landscape
            frame was stretched to fill it. That is the long distorted preview staff
            reported. `aspect-[4/3]` matches what phone cameras actually deliver, so the
            preview is the right shape on every screen and `object-cover` only ever trims a
            sliver from the long edge.
        -->
        <div class="relative aspect-[4/3] w-full overflow-hidden rounded-2xl border border-stone-200 bg-black">
            <video
                ref="video"
                class="absolute inset-0 h-full w-full object-cover"
                playsinline
                muted
            ></video>

            <!-- A framing guide, because the code has to be inside the view for the
                 detector to read it and people otherwise hold the phone too far away. -->
            <div class="pointer-events-none absolute inset-0 flex items-center justify-center">
                <div class="h-40 w-40 rounded-xl border-2 border-white/80"></div>
            </div>
        </div>

        <!--
            Offscreen scratch surface for the jsQR path. Not rendered and not positioned —
            it exists only to hand jsQR the raw pixels of the current video frame.
        -->
        <canvas ref="canvas" class="hidden"></canvas>

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
