<template>
  <Line :data="chartData" :options="options" />
</template>

<script>
import { Line } from 'vue-chartjs'
import {
    Chart as ChartJS, LineElement, PointElement, CategoryScale, LinearScale, Title, Tooltip, Legend,
} from 'chart.js'

ChartJS.register(LineElement, PointElement, CategoryScale, LinearScale, Title, Tooltip, Legend)

export default {
    components: { Line },
    props: {
        chartData: { type: Object, required: true },
        options: { type: Object, default: () => ({}) },
    },
}
</script>
