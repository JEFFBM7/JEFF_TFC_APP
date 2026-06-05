<script setup lang="ts">
import type { ApexOptions } from 'apexcharts'
import { computed, defineAsyncComponent } from 'vue'
import { baseOptions, chartColors } from './theme'
import type { ChartSeries } from '../../types'

const ApexChart = defineAsyncComponent(async () => (await import('vue3-apexcharts')).default)

const props = withDefaults(defineProps<{
  series: ChartSeries[]
  categories: string[]
  colors?: string[]
  height?: number
  horizontal?: boolean
  yMax?: number
  tooltipSuffix?: string
}>(), {
  colors: () => [chartColors.primary, chartColors.teal, chartColors.amber],
  height: 280,
  horizontal: false,
  yMax: undefined,
  tooltipSuffix: '',
})

const options = computed<ApexOptions>(() => ({
  ...baseOptions(props.height),
  chart: {
    ...baseOptions(props.height).chart,
    type: 'bar',
  },
  colors: props.colors,
  plotOptions: {
    bar: {
      horizontal: props.horizontal,
      borderRadius: 4,
      columnWidth: '48%',
      barHeight: '56%',
    },
  },
  stroke: { width: 0 },
  xaxis: {
    ...baseOptions(props.height).xaxis,
    categories: props.categories,
    max: props.horizontal ? props.yMax : undefined,
  },
  yaxis: {
    ...baseOptions(props.height).yaxis,
    max: props.horizontal ? undefined : props.yMax,
  },
  tooltip: {
    ...baseOptions(props.height).tooltip,
    y: {
      formatter: (value: number) => `${value}${props.tooltipSuffix}`,
    },
  },
}))
</script>

<template>
  <ApexChart type="bar" :height="height" :options="options" :series="series" />
</template>
