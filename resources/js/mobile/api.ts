import { session } from './session';

const mount = document.getElementById('mobile-app')!;
export const base = mount.dataset.base!;
const endpoint = mount.dataset.api!;

export class ApiError extends Error {
    constructor(public status: number, message: string, public errors: Record<string, string[]> = {}) {
        super(message);
    }
}

export async function api<T>(path: string, options: { body?: unknown; signal?: AbortSignal; method?: 'PATCH' } = {}): Promise<T> {
    const timeout = new AbortController();
    const timer = window.setTimeout(() => timeout.abort(), 20000);
    const abort = () => timeout.abort();
    options.signal?.addEventListener('abort', abort, { once: true });
    if (options.signal?.aborted) timeout.abort();
    try {
        const response = await fetch(`${endpoint}/${path}`, {
            method: options.method ?? (options.body === undefined ? 'GET' : 'POST'),
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                Accept: 'application/json',
                ...(options.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
                'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')!.content,
                ...(session.data?.store_id ? { 'X-Mobile-Store': String(session.data.store_id) } : {}),
            },
            body: options.body === undefined ? undefined : options.body instanceof FormData ? options.body : JSON.stringify(options.body),
            signal: timeout.signal,
        });
        const token = response.headers.get('X-CSRF-TOKEN');
        if (token) document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')!.content = token;
        const result = await response.json().catch(() => ({}));
        if (!response.ok) {
            if (response.status === 401) window.dispatchEvent(new Event('mobile:unauthenticated'));
            const messages: Record<number, string> = {
                401: 'Hisobga qayta kiring.', 403: 'Bu amal uchun ruxsat yo‘q yoki do‘kon tanlanmagan.',
                404: 'Ma’lumot topilmadi.', 419: 'Sessiya eskirgan. Sahifani yangilang.',
                429: 'So‘rovlar ko‘payib ketdi. Birozdan keyin qayta urinib ko‘ring.',
            };
            throw new ApiError(response.status, Object.values(result.errors ?? {}).flat()[0] as string || messages[response.status] || result.message || 'Serverda xatolik. Qayta urinib ko‘ring.', result.errors);
        }
        return result as T;
    } catch (error) {
        if (error instanceof ApiError) throw error;
        if (options.signal?.aborted) throw error;
        throw new ApiError(0, 'Aloqa uzildi yoki server javobi kechikdi. Saqlangan bo‘lishi mumkin — ayni formani qayta yuborish xavfsiz.');
    } finally {
        clearTimeout(timer);
        options.signal?.removeEventListener('abort', abort);
    }
}

const cache = new Map<string, { expires: number; value: unknown }>();
export function cached<T>(key: string): T | undefined {
    const entry = cache.get(key);
    return entry && entry.expires > Date.now() ? entry.value as T : undefined;
}
export function remember(key: string, value: unknown): void {
    cache.set(key, { expires: Date.now() + 30000, value });
}
export function clearCache(): void { cache.clear(); }
export const money = (amount: number, currency = 'UZS'): string => `${new Intl.NumberFormat('en-US').format(amount).replace(/,/g, ' ')} ${currency === 'UZS' ? 'so‘m' : currency}`;
export function dateLabel(value: string | null): string {
    const parts = value?.match(/^(\d{4})-(\d{2})-(\d{2})(?:$|[T ])/);
    return parts ? `${parts[3]}.${parts[2]}.${parts[1]}` : '—';
}
export function requestId(): string {
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    const hex = [...bytes].map(value => value.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0,8)}-${hex.slice(8,12)}-${hex.slice(12,16)}-${hex.slice(16,20)}-${hex.slice(20)}`;
}
