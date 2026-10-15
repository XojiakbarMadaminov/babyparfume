interface NativeDetector {
    detect(source: HTMLVideoElement): Promise<{ rawValue: string }[]>;
}

interface NativeDetectorConstructor {
    new (options: { formats: string[] }): NativeDetector;
    getSupportedFormats(): Promise<string[]>;
}

export interface CameraScanner {
    stop(): void;
    focus(): Promise<void>;
}

async function nativeDetector(): Promise<NativeDetector | undefined> {
    const Detector = (window as Window & { BarcodeDetector?: NativeDetectorConstructor }).BarcodeDetector;
    if (!Detector) return;
    try {
        const formats = await Detector.getSupportedFormats();
        if (formats.length) return new Detector({ formats });
    } catch {}
}

export async function startBarcodeScanner(video: HTMLVideoElement, onResult: (code: string) => void, signal: AbortSignal): Promise<CameraScanner> {
    let stream: MediaStream | undefined;
    let stopped = false;
    let nativeTimer: number | undefined;
    let fallbackTimer: number | undefined;
    let fallback: { stop(): void } | undefined;
    let fallbackStarting = false;
    let fallbackStarted = false;

    function stop() {
        if (stopped) return;
        stopped = true;
        window.clearTimeout(nativeTimer);
        window.clearTimeout(fallbackTimer);
        fallback?.stop();
        stream?.getTracks().forEach(track => track.stop());
        if (video.srcObject === stream) video.srcObject = null;
        signal.removeEventListener('abort', stop);
    }

    function accept(code: string) {
        if (stopped || !code.trim()) return;
        stop();
        onResult(code);
    }

    async function focus() {
        const track = stream?.getVideoTracks()[0];
        if (stopped || !track?.getCapabilities) return;
        try {
            const modes = (track.getCapabilities() as MediaTrackCapabilities & { focusMode?: string[] }).focusMode;
            const mode = modes?.includes('continuous') ? 'continuous' : modes?.includes('single-shot') ? 'single-shot' : undefined;
            if (!mode) return;
            await track.applyConstraints({ advanced: [{ focusMode: mode } as MediaTrackConstraintSet] });
        } catch {}
    }

    signal.addEventListener('abort', stop, { once: true });
    try {
        if (signal.aborted) throw new DOMException('Scanner cancelled', 'AbortError');
        stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
            audio: false,
        });
        if (stopped) {
            stream.getTracks().forEach(track => track.stop());
            throw new DOMException('Scanner cancelled', 'AbortError');
        }
        video.srcObject = stream;
        await video.play();
        if (stopped) throw new DOMException('Scanner cancelled', 'AbortError');
        void focus();
        const detector = await nativeDetector();
        if (stopped) throw new DOMException('Scanner cancelled', 'AbortError');

        async function startFallback() {
            if (stopped || fallbackStarting || fallbackStarted) return;
            fallbackStarting = true;
            try {
                const [{ BrowserMultiFormatReader }, { DecodeHintType }] = await Promise.all([
                    import('@zxing/browser'), import('@zxing/library'),
                ]);
                if (stopped) return;
                const hints = new Map([[DecodeHintType.TRY_HARDER, true]]);
                const reader = new BrowserMultiFormatReader(hints, { delayBetweenScanAttempts: detector ? 300 : 150 });
                const active = await reader.decodeFromVideoElement(video, result => {
                    if (result) accept(result.getText());
                });
                if (stopped) active.stop();
                else fallback = active;
                fallbackStarted = true;
            } finally {
                fallbackStarting = false;
            }
        }

        if (detector) {
            let failures = 0;
            async function scanNative() {
                if (stopped) return;
                if (video.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA) {
                    try {
                        const results = await detector!.detect(video);
                        if (stopped) return;
                        const result = results.find(candidate => candidate.rawValue?.trim());
                        if (result) { accept(result.rawValue); return; }
                        failures = 0;
                    } catch {
                        failures++;
                    }
                }
                if (stopped) return;
                if (failures >= 3) {
                    void startFallback().catch(() => {});
                }
                nativeTimer = window.setTimeout(scanNative, failures >= 3 ? 500 : 120);
            }
            void scanNative();
            fallbackTimer = window.setTimeout(() => { void startFallback().catch(() => {}); }, 1500);
        } else {
            await startFallback();
        }

        return { stop, focus };
    } catch (error) {
        stop();
        throw error;
    }
}
