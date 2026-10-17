const photoStyles = `
    :host{display:block;font:inherit;color:inherit;color-scheme:light dark}:host-context(html.dark){color-scheme:dark}:host-context(html.light){color-scheme:light}
    button{font:inherit;cursor:pointer;border:1px solid #9ca3af66;border-radius:12px;padding:12px 18px;background:#484ab5;color:white}
    button:disabled{opacity:.5;cursor:wait}.actions{display:flex;flex-wrap:wrap;gap:12px;margin-top:12px}
    .secondary{background:Canvas;color:CanvasText}small{display:block;margin-top:10px;opacity:.75}
    dialog{box-sizing:border-box;width:min(94vw,720px);max-height:94dvh;overflow:auto;background:Canvas;color:CanvasText;border:1px solid #9ca3af66;border-radius:18px;padding:20px}
    dialog::backdrop{background:#000b}h2{font-size:20px;margin:0 0 16px}video{width:100%;max-height:65dvh;background:black;border-radius:12px}
    img{display:block;max-width:100%;max-height:80dvh;object-fit:contain;margin:auto;border-radius:12px}
    .preview{max-height:220px;margin:12px 0}.error{color:#ef4444}input[hidden],[hidden]{display:none!important}
`;

class DebtorPhotoPicker extends HTMLElement {
    private stream?: MediaStream;
    private previewUrl?: string;
    private root = this.attachShadow({ mode: 'open' });

    connectedCallback(): void {
        this.root.innerHTML = `<style>${photoStyles}</style>
            <button type="button" class="choose">Rasm yuklash</button>
            <small>${this.hasAttribute('optimized') ? 'JPG, PNG yoki WebP · 10 MB gacha. Yuklashdan oldin 960 pikselgacha kichraytiriladi va siqiladi.' : 'JPG, PNG yoki WebP · 10 MB gacha · 6000 × 6000 gacha. Sifat yo‘qotilmasdan saqlanadi.'}</small>
            <img class="preview" hidden alt="Tanlangan rasm"><button type="button" class="remove secondary" hidden>Tanlovni bekor qilish</button>
            <p class="error" role="alert"></p>
            <input class="file" type="file" accept="image/jpeg,image/png,image/webp" hidden>
            <input class="native-camera" type="file" accept="image/*" capture="environment" hidden>
            <dialog class="source" aria-label="Rasmni tanlang"><h2>Rasmni tanlang</h2><div class="actions"><button type="button" class="camera">Kamera orqali olish</button><button type="button" class="upload secondary">Fayldan yuklash</button><button type="button" class="cancel secondary">Bekor qilish</button></div></dialog>
            <dialog class="capture" aria-label="Kamera orqali olish"><h2>Kamera orqali olish</h2><video autoplay muted playsinline></video><p class="camera-error" role="alert"></p><div class="actions"><button type="button" class="shutter" disabled>Rasmga olish</button><button type="button" class="fallback secondary">Telefon kamerasi / fayl</button><button type="button" class="close secondary">Yopish</button></div></dialog>`;
        this.button('.choose').onclick = () => this.dialog('.source').showModal();
        this.button('.cancel').onclick = () => this.dialog('.source').close();
        this.button('.upload').onclick = () => {
            this.dialog('.source').close();
            this.input('.file').click();
        };
        this.button('.camera').onclick = () => {
            this.dialog('.source').close();
            if (matchMedia('(pointer: coarse)').matches) this.input('.native-camera').click();
            else void this.startCamera();
        };
        this.button('.fallback').onclick = () => {
            this.dialog('.capture').close();
            this.input('.native-camera').click();
        };
        this.button('.close').onclick = () => this.dialog('.capture').close();
        this.dialog('.capture').addEventListener('close', () => this.stopCamera());
        this.dialog('.capture').addEventListener('cancel', () => this.stopCamera());
        this.button('.shutter').onclick = () => void this.capture();
        for (const selector of ['.file', '.native-camera']) {
            this.input(selector).onchange = () => {
                const file = this.input(selector).files?.[0];
                if (file) void this.select(file);
                this.input(selector).value = '';
            };
        }
        this.button('.remove').onclick = () => {
            this.clearPreview();
            this.dispatchEvent(new CustomEvent('photo-selected', { detail: null, bubbles: true, composed: true }));
        };
    }

    disconnectedCallback(): void {
        this.stopCamera();
        if (this.previewUrl) URL.revokeObjectURL(this.previewUrl);
    }

    private button(selector: string): HTMLButtonElement { return this.root.querySelector(selector)!; }
    private input(selector: string): HTMLInputElement { return this.root.querySelector(selector)!; }
    private dialog(selector: string): HTMLDialogElement { return this.root.querySelector(selector)!; }

    private async startCamera(): Promise<void> {
        const dialog = this.dialog('.capture');
        dialog.showModal();
        this.root.querySelector('.camera-error')!.textContent = '';
        try {
            if (!navigator.mediaDevices?.getUserMedia) throw new Error('unsupported');
            const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 4096 }, height: { ideal: 2160 } }, audio: false });
            if (!this.isConnected || !dialog.open) {
                stream.getTracks().forEach(track => track.stop());
                return;
            }
            this.stream = stream;
            const video = this.root.querySelector('video')!;
            video.srcObject = stream;
            await video.play();
            this.button('.shutter').disabled = false;
        } catch {
            this.stopCamera();
            this.root.querySelector('.camera-error')!.textContent = 'Kamera ochilmadi. HTTPS va kamera ruxsatini tekshiring yoki fayldan yuklang.';
        }
    }

    private async capture(): Promise<void> {
        const video = this.root.querySelector('video')!;
        if (!video.videoWidth) return;
        this.button('.shutter').disabled = true;
        const canvas = document.createElement('canvas');
        const optimized = this.hasAttribute('optimized');
        const ratio = optimized ? Math.min(1, 960 / Math.max(video.videoWidth, video.videoHeight)) : 1;
        canvas.width = Math.max(1, Math.round(video.videoWidth * ratio));
        canvas.height = Math.max(1, Math.round(video.videoHeight * ratio));
        canvas.getContext('2d')!.drawImage(video, 0, 0, canvas.width, canvas.height);
        try {
            const blob = await new Promise<Blob | null>(resolve => canvas.toBlob(resolve, optimized ? 'image/jpeg' : 'image/png', 0.85));
            if (blob && this.isConnected && this.dialog('.capture').open) {
                await this.select(new File([blob], optimized ? 'kamera.jpg' : 'kamera.png', { type: blob.type }));
                this.dialog('.capture').close();
            }
        } finally {
            canvas.width = canvas.height = 0;
            this.button('.shutter').disabled = !this.stream;
        }
    }

    private async select(file: File): Promise<void> {
        const error = this.root.querySelector('.error')!;
        error.textContent = '';
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 10 * 1024 * 1024) {
            error.textContent = '10 MB gacha bo‘lgan JPG, PNG yoki WebP rasmini tanlang. Telefon kamerasi formatini JPG ga sozlashingiz mumkin.';
            return;
        }
        if (this.hasAttribute('optimized')) {
            this.button('.choose').disabled = this.button('.remove').disabled = true;
            this.dispatchEvent(new CustomEvent('photo-processing', { detail: true, bubbles: true, composed: true }));
            try {
                file = await this.optimize(file);
            } catch {
                error.textContent = 'Rasmni siqib bo‘lmadi. Kichikroq JPG, PNG yoki WebP rasm tanlang.';
                return;
            } finally {
                this.button('.choose').disabled = this.button('.remove').disabled = false;
                this.dispatchEvent(new CustomEvent('photo-processing', { detail: false, bubbles: true, composed: true }));
            }
            if (!this.isConnected) return;
        }
        this.clearPreview();
        this.previewUrl = URL.createObjectURL(file);
        const image = this.root.querySelector<HTMLImageElement>('.preview')!;
        image.src = this.previewUrl;
        image.hidden = false;
        this.button('.remove').hidden = false;
        this.dispatchEvent(new CustomEvent('photo-selected', { detail: file, bubbles: true, composed: true }));
    }

    private async optimize(file: File): Promise<File> {
        const url = URL.createObjectURL(file);
        const canvas = document.createElement('canvas');
        try {
            const image = new Image();
            image.src = url;
            await image.decode();
            const ratio = Math.min(1, 960 / Math.max(image.naturalWidth, image.naturalHeight));
            canvas.width = Math.max(1, Math.round(image.naturalWidth * ratio));
            canvas.height = Math.max(1, Math.round(image.naturalHeight * ratio));
            const context = canvas.getContext('2d');
            if (!context) throw new Error('Canvas unavailable');
            context.drawImage(image, 0, 0, canvas.width, canvas.height);
            let blob: Blob | null = null;
            for (const quality of [0.78, 0.65, 0.5]) {
                blob = await new Promise<Blob | null>(resolve => canvas.toBlob(resolve, 'image/webp', quality));
                if (blob?.type !== 'image/webp') {
                    context.fillStyle = '#fff';
                    context.fillRect(0, 0, canvas.width, canvas.height);
                    context.drawImage(image, 0, 0, canvas.width, canvas.height);
                    blob = await new Promise<Blob | null>(resolve => canvas.toBlob(resolve, 'image/jpeg', quality));
                }
                if (blob && blob.size <= 512 * 1024) break;
            }
            if (!blob || blob.size > 1024 * 1024) throw new Error('Image too large');
            return new File([blob], blob.type === 'image/webp' ? 'mahsulot.webp' : 'mahsulot.jpg', { type: blob.type });
        } finally {
            URL.revokeObjectURL(url);
            canvas.width = canvas.height = 0;
        }
    }

    private clearPreview(): void {
        if (this.previewUrl) URL.revokeObjectURL(this.previewUrl);
        this.previewUrl = undefined;
        const image = this.root.querySelector<HTMLImageElement>('.preview')!;
        image.removeAttribute('src');
        image.hidden = true;
        this.button('.remove').hidden = true;
    }

    private stopCamera(): void {
        this.stream?.getTracks().forEach(track => track.stop());
        this.stream = undefined;
        this.root.querySelector('video')!.srcObject = null;
        this.button('.shutter').disabled = true;
    }
}

class DebtorPhotoViewer extends HTMLElement {
    connectedCallback(): void {
        if (this.shadowRoot) return;
        const root = this.attachShadow({ mode: 'open' });
        root.innerHTML = `<style>${photoStyles}.thumbnail{background:none;padding:0;border:0}.thumbnail img{max-height:240px} .full{width:min(96vw,1400px)}</style><button type="button" class="thumbnail" part="thumbnail" aria-label="Rasmni kattalashtirish"><img part="image" alt="Qarzdor rasmi"></button><dialog class="full" aria-label="Qarzdor rasmi"><div class="actions"><button type="button" class="secondary">Yopish</button></div><img alt="Qarzdor rasmi — to‘liq o‘lchamda"></dialog>`;
        for (const image of root.querySelectorAll('img')) image.src = this.getAttribute('src') ?? '';
        const dialog = root.querySelector('dialog')!;
        root.querySelector<HTMLButtonElement>('.thumbnail')!.onclick = () => dialog.showModal();
        dialog.querySelector('button')!.onclick = () => dialog.close();
        dialog.onclick = event => { if (event.target === dialog) dialog.close(); };
    }
}

if (!customElements.get('debtor-photo-picker')) customElements.define('debtor-photo-picker', DebtorPhotoPicker);
if (!customElements.get('debtor-photo-viewer')) customElements.define('debtor-photo-viewer', DebtorPhotoViewer);
