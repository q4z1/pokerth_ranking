<template>
  <nav class="flex gap-1 bg-pth-elevated p-1 rounded" style="font-size: 0.75rem">
    <button
      v-for="tab in tabs"
      :key="tab.id"
      class="px-3 py-1 text-xs font-semibold transition-colors cursor-pointer outline-none focus:outline-none"
      :class="activeTab === tab.id
        ? 'bg-pth-accent text-white'
        : 'text-pth-text-secondary hover:text-pth-text hover:bg-pth-surface'"
      @click="$emit('update:activeTab', tab.id)"
    >
      {{ tab.label }}
      <span v-if="tab.count != null" class="ml-1 opacity-75">({{ tab.count }})</span>
    </button>
  </nav>
</template>

<script setup>
import { computed } from 'vue'
import { useGameCacheStore } from '@/stores'

defineProps({
  activeTab: { type: String, required: true }
})

defineEmits(['update:activeTab'])

const store = useGameCacheStore()

const tabs = computed(() => [
  { id: 'games', label: 'Games', count: store.gameList.length },
  { id: 'players', label: 'Players', count: store.playerList.length },
  { id: 'chat', label: 'Chat', count: null }
])
</script>

<style scoped>
button {
  font-size: 0.75rem;
}
</style>
