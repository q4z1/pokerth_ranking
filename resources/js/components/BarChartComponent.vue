<template>
  <Bar :data="chartData" :options="mergedOptions" />
</template>

<script>
import { Bar } from 'vue-chartjs'
import {
    Chart as ChartJS, BarElement, CategoryScale, LinearScale, Title, Tooltip, Legend,
} from 'chart.js'
import { CHART_TEXT_COLOR, CHART_GRID_COLOR } from '../chartColors.js'

ChartJS.register(BarElement, CategoryScale, LinearScale, Title, Tooltip, Legend)

// Canvas-Hintergrund transparent (sonst weiß bei dark mode)
const transparentBg = {
    id: 'transparentBg',
    beforeDraw: (chart) => {
        const ctx = chart.canvas.getContext('2d')
        ctx.save()
        ctx.globalCompositeOperation = 'destination-over'
        ctx.fillStyle = 'transparent'
        ctx.fillRect(0, 0, chart.width, chart.height)
        ctx.restore()
    }
}
ChartJS.register(transparentBg)

export default {
    components: { Bar },
    props: {
        chartData: { type: Object, required: true },
        options:   { type: Object, default: () => ({}) },
    },
    computed: {
        mergedOptions() {
            return {
                responsive: true,
                maintainAspectRatio: false,
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
