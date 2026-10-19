<script setup lang="ts">
defineProps<{ name: string }>();
const paths: Record<string, string> = {
    chart: 'M4 4v16h16M8 16v-4m4 4V8m4 8V5',
    trend: 'm3 17 6-6 4 4 8-10m-6 0h6v6',
    calendar: 'M4 5h16v16H4zM8 3v4m8-4v4M4 11h16',
    receipt: 'M5 3h14v18l-3-2-4 2-4-2-3 2V3Zm3 5h8m-8 4h8m-8 4h4',
    percent: 'M6 18 18 6M8 6a2 2 0 1 1-4 0 2 2 0 0 1 4 0m12 12a2 2 0 1 1-4 0 2 2 0 0 1 4 0',
    wallet: 'M3 6h17v15H3V6Zm0 0V3h15v3m-3 6h6v5h-6v-5Z',
    edit: 'm16 3 5 5M4 16 16 4a3.5 3.5 0 0 1 5 5L9 21H3l1-5Z',
    trash: 'M3 6h18M9 6V4h6v2M5 6l1 15h12l1-15M10 10v7m4-7v7',
    cart: 'M3 3h2l3 12h10l3-8H6M9 21h.01M18 21h.01',
    scan: 'M7 3H3v4m14-4h4v4M3 17v4h4m14-4v4h-4M7 8v8m3-8v8m4-8v8m3-8v8',
    users: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m20 0v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0',
    box: 'm12 3 9 5v9l-9 5-9-5V8l9-5Zm0 10 9-5M12 13 3 8m9 5v9M7.5 5.5l9 5',
    plus: 'M12 5v14M5 12h14',
    minus: 'M5 12h14',
    refresh: 'M20 7v5h-5M4 17v-5h5M6.1 6.1a8 8 0 0 1 13.4 3.4M4.5 14.5a8 8 0 0 0 13.4 3.4',
    back: 'm12 5-7 7 7 7M5 12h14',
    next: 'm9 5 7 7-7 7',
    search: 'M21 21l-5-5M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0',
    check: 'm5 12 4 4L19 6',
    store: 'M3 10v11h18V10M2 3h20l-2 7H4L2 3Zm6 18v-7h8v7',
    logout: 'M9 3H4v18h5m6-14 5 5-5 5m-6-5h11',
    camera: 'M14 4h-4L8 7H3v14h18V7h-5l-2-3Zm2 10a4 4 0 1 1-8 0 4 4 0 0 1 8 0',
    info: 'M12 8h.01M12 11v6M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0',
    stop: 'M7 7h10v10H7z',
    chevron: 'm6 9 6 6 6-6',
    down: 'M7 7v10h10M7 17 18 6',
    warning: 'M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z',
};
</script>
<template><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="paths[name] || paths.box" /></svg></template>
