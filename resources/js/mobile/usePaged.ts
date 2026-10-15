import { onBeforeUnmount, ref, watch, type Ref } from 'vue';
import { api, cached, remember } from './api';
import { session } from './session';
import type { Page } from './types';

export function usePaged<T>(path: string, query: Ref<string>, filter?: Ref<string>) {
    const result = ref<Page<T>>({ data: [] }) as Ref<Page<T>>;
    const busy = ref(false);
    const error = ref('');
    const page = ref(1);
    let controller: AbortController | undefined;
    let timer: number | undefined;
    let sequence = 0;

    async function load(force = false): Promise<void> {
        controller?.abort();
        const version = ++sequence;
        const params = new URLSearchParams({ q: query.value, page: String(page.value), filter: filter?.value ?? 'all' });
        const key = `${session.data?.store_id}/${path}?${params}`;
        const saved = force ? undefined : cached<Page<T>>(key);
        if (saved) { result.value = saved; busy.value = false; error.value = ''; return; }
        controller = new AbortController();
        busy.value = true;
        error.value = '';
        try {
            const response = await api<Page<T>>(`${path}?${params}`, { signal: controller.signal });
            if (version !== sequence) return;
            result.value = response;
            remember(key, response);
        } catch (reason) {
            if (version === sequence && !controller?.signal.aborted) error.value = (reason as Error).message;
        } finally { if (version === sequence) busy.value = false; }
    }
    watch([query, ...(filter ? [filter] : [])], () => {
        clearTimeout(timer);
        controller?.abort();
        sequence++;
        result.value = { data: [] };
        page.value = 1;
        busy.value = true;
        timer = window.setTimeout(() => void load(), 250);
    });
    onBeforeUnmount(() => { sequence++; controller?.abort(); clearTimeout(timer); });
    void load();
    return { result, busy, error, page, load };
}
