<template>
  <Line :data="chartData" :options="mergedOptions" />
</template>

<script>
import { Line } from 'vue-chartjs'
import {
    Chart as ChartJS, LineElement, PointElement, CategoryScale, LinearScale, Title, Tooltip, Legend,
} from 'chart.js'
import { CHART_TEXT_COLOR, CHART_GRID_COLOR } from '../chartColors.js'

ChartJS.register(LineElement, PointElement, CategoryScale, LinearScale, Title, Tooltip, Legend)

export default {
    components: { Line },
    props: {
        chartData: { type: Object, required: true },
        options:   { type: Object, default: () => ({}) },
    },
    computed: {
        mergedOptions() {
            return {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    x: {
                        ticks: { color: CHART_TEXT_COLOR },
                        grid:  { color: CHART_GRID_COLOR },
                    },
                    y: {
                        ticks: { color: CHART_TEXT_COLOR },
                        grid:  { color: CHART_GRID_COLOR },
                    },
                },
                plugins: {
                    legend: { labels: { color: CHART_TEXT_COLOR } },
                },
                ...this.options,
            }
        },
    },
}
</script>
