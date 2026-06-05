<script setup lang="ts">
import type { ApexOptions } from 'apexcharts'
import { computed, defineAsyncComponent } from 'vue'
import { chartColors } from './theme'
import type { ChartSeries } from '../../types'

const ApexChart = defineAsyncComponent(async () => (await import('vue3-apexcharts')).default)

const props = withDefaults(defineProps<{
  series: ChartSeries[]
  colors?: string[]
  height?: number
  yMax?: number
  valueSuffix?: string
}>(), {
  colors: () => [chartColors.primary],
  height: 72,
  yMax: 20,
  valueSuffix: '%',
})

const options = computed<ApexOptions>(() => ({
  chart: {
    sparkline: { enabled: true },
    animations: { enabled: true, speed: 350 },
  },
  colors: props.colors,
  dataLabels: { enabled: false },
  stroke: { curve: 'smooth', width: 2.5 },
  fill: {
    type: 'gradient',
    gradient: { opacityFrom: 0.22, opacityTo: 0.02 },
  },
  tooltip: {
    theme: 'light',
    y: {
      formatter: (value: number) => `${value.toFixed(2)}${props.valueSuffix}`,
    },
  },
  yaxis: { min: 0, max: props.yMax },
}))
</script>

<template>
  <ApexChart type="area" :height="height" :options="options" :series="series" />
</template>
