<script setup lang="ts">
import type { ApexOptions } from 'apexcharts'
import { computed, defineAsyncComponent } from 'vue'
import { baseOptions, chartColors, resolveCountAxisMax } from './theme'
import type { ChartSeries } from '../../types'

const ApexChart = defineAsyncComponent(async () => (await import('vue3-apexcharts')).default)

const props = withDefaults(defineProps<{
  series: ChartSeries[]
  categories: string[]
  colors?: string[]
  height?: number
  yMax?: number
  tooltipSuffix?: string
}>(), {
  colors: () => [chartColors.primary, chartColors.amber],
  height: 280,
  yMax: undefined,
  tooltipSuffix: '',
})

const axisMax = computed(() => resolveCountAxisMax(props.series, props.yMax))

const options = computed<ApexOptions>(() => ({
  ...baseOptions(props.height),
  colors: props.colors,
  legend: {
    ...baseOptions(props.height).legend,
    position: 'top',
    horizontalAlign: 'left',
    offsetY: -4,
  },
  grid: {
    ...baseOptions(props.height).grid,
    padding: { left: 4, right: 12, top: 0, bottom: 0 },
  },
  fill: {
    type: 'gradient',
    gradient: {
      shadeIntensity: 0.2,
      opacityFrom: 0.32,
      opacityTo: 0.04,
      stops: [0, 85, 100],
    },
  },
  xaxis: {
    ...baseOptions(props.height).xaxis,
    categories: props.categories,
    labels: {
      ...baseOptions(props.height).xaxis?.labels,
      rotate: props.categories.length > 8 ? -35 : 0,
      hideOverlappingLabels: true,
    },
  },
  yaxis: {
    ...baseOptions(props.height).yaxis,
    min: 0,
    max: axisMax.value,
    tickAmount: Math.min(axisMax.value, 5),
    forceNiceScale: false,
    labels: {
      ...baseOptions(props.height).yaxis?.labels,
      formatter: (value: number) => (Number.isInteger(value) ? `${value}` : ''),
    },
  },
  tooltip: {
    ...baseOptions(props.height).tooltip,
    y: {
      formatter: (value: number) => `${Math.round(value)}${props.tooltipSuffix}`,
    },
  },
}))
</script>

<template>
  <ApexChart type="area" :height="height" :options="options" :series="series" />
</template>
