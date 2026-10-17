import { startBarcodeScanner, type CameraScanner } from './barcodeScanner';
import '../../css/mobile-sales.css';

class PosMobileScanner extends HTMLElement {
    private scanner?: CameraScanner;
    private controller?: AbortController;
    private onVisibility = () => { if (document.hidden) this.stop(); };

    connectedCallback() {
        this.addEventListener('pos-camera-start', this.start);
        this.addEventListener('pos-camera-stop', this.stop);
        document.addEventListener('visibilitychange', this.onVisibility);
    }

    disconnectedCallback() {
        this.stop();
        this.removeEventListener('pos-camera-start', this.start);
        this.removeEventListener('pos-camera-stop', this.stop);
        document.removeEventListener('visibilitychange', this.onVisibility);
    }

    private stop = () => {
        this.controller?.abort();
        this.scanner?.stop();
        this.controller = undefined;
        this.scanner = undefined;
    };

    private start = async () => {
        this.stop();
        const controller = new AbortController();
        this.controller = controller;
        try {
            if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
                throw new Error('Kamera uchun HTTPS kerak. Kodni qidiruvga qo‘lda kiriting.');
            }
            const video = this.querySelector('video');
            if (!video) return;
            this.scanner = await startBarcodeScanner(video, code => {
                this.stop();
                this.dispatchEvent(new CustomEvent('pos-barcode', { bubbles: true, detail: code }));
            }, controller.signal);
            if (controller.signal.aborted) this.scanner?.stop();
        } catch (reason) {
            if (controller.signal.aborted) return;
            this.stop();
            const error = reason as Error;
            this.dispatchEvent(new CustomEvent('pos-camera-error', {
                bubbles: true,
                detail: error.name === 'NotAllowedError'
                    ? 'Kameraga ruxsat bering yoki kodni qo‘lda kiriting.'
                    : error.name === 'NotFoundError'
                        ? 'Kamera topilmadi. Kodni qo‘lda kiriting.'
                        : error.message || 'Kamerani ochib bo‘lmadi. Qayta urinib ko‘ring.',
            }));
        }
    };
}

if (!customElements.get('pos-mobile-scanner')) customElements.define('pos-mobile-scanner', PosMobileScanner);

class PosMobileNotices extends HTMLElement {
    private observer?: MutationObserver;

    connectedCallback() {
        const source = document.querySelector('.fi-no');
        if (!source) return;
        const update = () => {
            const messages = Array.from(source.querySelectorAll('.fi-no-notification')).map(notification => {
                return Array.from(notification.querySelectorAll('.fi-no-notification-title, .fi-no-notification-body'))
                    .map(part => part.textContent?.trim()).filter(Boolean).join(' — ');
            });
            this.textContent = messages.join('\n');
        };
        this.observer = new MutationObserver(update);
        this.observer.observe(source, { childList: true, subtree: true, characterData: true });
        update();
    }

    disconnectedCallback() {
        this.observer?.disconnect();
    }
}

if (!customElements.get('pos-mobile-notices')) customElements.define('pos-mobile-notices', PosMobileNotices);
