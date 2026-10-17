export type StatisticsPeriod = 'today' | 'week' | 'month' | 'range';

export interface StatisticsFilter {
    storeId: number;
    period: StatisticsPeriod;
    start: string;
    end: string;
}

export interface SalesStatistics {
    filter: StatisticsFilter;
    salesAmount: number;
    salesCount: number;
    averageReceipt: number | null;
    profit: number | null;
    marginPercent: number | null;
    changePercent: number | null;
    trend: { label: string; amount: number }[];
    payments: { key: string; label: string; amount: number; percent: number }[];
    topProducts: { id: number; name: string; quantity: number; amount: number; image?: string | null }[];
}

export function statisticsMoney(value: number | null | undefined): string {
    if (value == null || !Number.isFinite(value)) return '—';
    return `${new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 }).format(value).replaceAll(',', ' ')} so‘m`;
}

export function statisticsDate(date: Date): string {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}
