import { reactive } from 'vue';
import type { Session } from './types';

export const session = reactive<{ data: Session | null; busy: boolean; notice: string }>({ data: null, busy: false, notice: '' });
