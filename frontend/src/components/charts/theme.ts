import type { ApexOptions } from 'apexcharts'
import type { ChartSeries } from '../../types'

export const chartColors = {
  primary: '#2563eb',
  teal: '#0f766e',
  amber: '#d97706',
  danger: '#dc2626',
  muted: '#64748b',
  grid: '#e2e8f0',
  text: '#334155',
  softText: '#64748b',
}

export function peakSeriesValue(series: ChartSeries[]): number {
  return series.reduce<number>((peak, serie) => {
    const rowPeak = serie.data.reduce<number>((max, value) => {
      if (value === null || !Number.isFinite(value)) return max
      return Math.max(max, value)
    }, 0)
    return Math.max(peak, rowPeak)
  }, 0)
}

/** Échelle entière pour compteurs (absences, retards, effectifs…). */
export function resolveCountAxisMax(series: ChartSeries[], explicit?: number): number {
  if (explicit !== undefined) return explicit
  const peak = peakSeriesValue(series)
  if (peak <= 0) return 5
  if (peak <= 5) return 5
  return Math.ceil(peak * 1.15)
}

export function baseOptions(height = 280): ApexOptions {
  return {
    chart: {
      height,
      fontFamily: 'Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
      toolbar: { show: false },
      zoom: { enabled: false },
      animations: { enabled: true, speed: 450 },
    },
    dataLabels: { enabled: false },
    grid: {
      borderColor: chartColors.grid,
      strokeDashArray: 4,
      padding: { left: 8, right: 8 },
    },
    legend: {
      fontSize: '12px',
      labels: { colors: chartColors.softText },
      markers: { size: 6 },
    },
    stroke: {
      curve: 'smooth',
      width: 3,
    },
    tooltip: {
      theme: 'light',
      shared: true,
      intersect: false,
    },
    xaxis: {
      labels: { style: { colors: chartColors.softText, fontSize: '11px' } },
      axisBorder: { color: chartColors.grid },
      axisTicks: { color: chartColors.grid },
    },
    yaxis: {
      labels: { style: { colors: chartColors.softText, fontSize: '11px' } },
    },
  }
}
