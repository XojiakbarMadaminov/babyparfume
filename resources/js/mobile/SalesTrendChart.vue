<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import { statisticsMoney } from './salesStatistics';

const props = defineProps<{ points: { label: string; amount: number }[] }>();
const selected = ref<number | null>(null);
const gradientId = `sales-trend-${useId()}`;
const width = 320;
const height = 148;
const inset = 12;
const coordinates = computed(() => {
    const amounts = props.points.map(point => point.amount);
    const minimum = Math.min(0, ...amounts);
    const maximum = Math.max(1, ...amounts);
    return props.points.map((point, index) => ({
        ...point,
        x: props.points.length === 1 ? width / 2 : inset + index * (width - inset * 2) / (props.points.length - 1),
        y: height - inset - (point.amount - minimum) / (maximum - minimum) * (height - inset * 2),
    }));
});
const line = computed(() => coordinates.value.map((point, index) => `${index ? 'L' : 'M'}${point.x},${point.y}`).join(' '));
const area = computed(() => coordinates.value.length ? `${line.value} L${coordinates.value.at(-1)!.x},${height - inset} L${coordinates.value[0].x},${height - inset} Z` : '');
const active = computed(() => selected.value === null ? null : coordinates.value[selected.value]);
watch(() => props.points, () => { selected.value = null; });
function selectPoint(event: PointerEvent) {
    const bounds = (event.currentTarget as SVGSVGElement).getBoundingClientRect();
    const pointerX = (event.clientX - bounds.left) / bounds.width * width;
    selected.value = coordinates.value.reduce((closest, point, index, points) => Math.abs(point.x - pointerX) < Math.abs(points[closest].x - pointerX) ? index : closest, 0);
}
function movePoint(direction: number) {
    selected.value = Math.max(0, Math.min(props.points.length - 1, (selected.value ?? -1) + direction));
}
</script>

<template>
    <div class="sales-trend">
        <p class="sales-trend-readout" aria-live="polite"><template v-if="active"><span>{{ active.label }}</span><strong>{{ statisticsMoney(active.amount) }}</strong></template><span v-else>Nuqtani bosing yoki yo‘nalish tugmalaridan foydalaning.</span></p>
        <svg :viewBox="`0 0 ${width} ${height}`" class="sales-trend-svg" role="group" aria-label="Sotuv dinamikasi. Chap va o‘ng tugmalar bilan qiymatlarni ko‘ring." tabindex="0" @pointerdown="selectPoint" @keydown.right.prevent="movePoint(1)" @keydown.left.prevent="movePoint(-1)" @keydown.home.prevent="selected = 0" @keydown.end.prevent="selected = points.length - 1" @keydown.escape="selected = null">
            <defs><linearGradient :id="gradientId" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="currentColor" stop-opacity=".22" /><stop offset="100%" stop-color="currentColor" stop-opacity="0" /></linearGradient></defs>
            <path v-for="gridY in [12, 74, 136]" :key="gridY" :d="`M12,${gridY} H308`" class="sales-trend-grid" />
            <path :d="area" :fill="`url(#${gradientId})`" />
            <path :d="line" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
            <circle v-if="coordinates.length === 1" :cx="coordinates[0].x" :cy="coordinates[0].y" r="4" fill="currentColor" />
            <template v-if="active"><path :d="`M${active.x},12 V136`" class="sales-trend-guide" /><circle :cx="active.x" :cy="active.y" r="5" fill="currentColor" /></template>
        </svg>
        <div class="sales-trend-axis" aria-hidden="true"><span>{{ points[0]?.label }}</span><span v-if="points.length > 2">{{ points[Math.floor((points.length - 1) / 2)]?.label }}</span><span v-if="points.length > 1">{{ points.at(-1)?.label }}</span></div>
        <details class="sales-trend-values"><summary>Qiymatlarni ro‘yxatda ko‘rish</summary><ul><li v-for="(point, index) in points" :key="index"><span>{{ point.label }}</span><strong>{{ statisticsMoney(point.amount) }}</strong></li></ul></details>
    </div>
</template>

<style scoped>
.sales-trend-readout { min-height: 43px; display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 4px 8px; font-size: 12px; }
.sales-trend-readout strong { color: var(--mobile-text); font-size: 14px; font-variant-numeric: tabular-nums; }
.sales-trend-svg { display: block; width: 100%; height: auto; color: var(--mobile-accent); cursor: crosshair; touch-action: pan-y; }
.sales-trend-grid { stroke: var(--mobile-line); stroke-width: 1; stroke-dasharray: 3 5; }
.sales-trend-guide { stroke: currentColor; stroke-width: 1; stroke-dasharray: 3 4; opacity: .5; }
.sales-trend-axis { display: flex; justify-content: space-between; gap: 8px; color: var(--mobile-muted); font-size: 11px; }
.sales-trend-values { margin-top: 16px; font-size: 12px; color: var(--mobile-muted); }
.sales-trend-values summary { min-height: 44px; display: flex; align-items: center; cursor: pointer; color: var(--mobile-accent); }
.sales-trend-values ul { padding: 0; margin: 0; list-style: none; }
.sales-trend-values li { display: flex; justify-content: space-between; gap: 12px; padding-block: 8px; border-top: 1px solid var(--mobile-line); }
.sales-trend-values strong { color: var(--mobile-text); font-weight: 500; }
</style>
