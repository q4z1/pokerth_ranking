<template>
  <div class="flex items-center justify-between px-4 py-2 border-b border-pth-border" style="background: linear-gradient(to right, var(--pth-grad-start), var(--pth-grad-end));">
    <div class="status-bar-text flex items-center gap-2">
      <span class="text-white/80">Logged in as:</span>
      <span
        class="font-semibold text-white"
      >
        {{ connected ? nickName : 'offline' }}
      </span>
    </div>
    <div class="flex items-center gap-3">
      <span class="text-white/50 text-xs">v{{ versionString }}</span>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useGameCacheStore } from '@/stores'
import { POKERTH_VERSION_MAJOR, POKERTH_VERSION_MINOR, POKERTH_VERSION_PATCH } from '@/constants'

const store = useGameCacheStore()

const connected = computed(() => store.connected)
const nickName = computed(() => store.nickName)
const versionString = `${POKERTH_VERSION_MAJOR}.${POKERTH_VERSION_MINOR}.${POKERTH_VERSION_PATCH}`
</script>

<style scoped>
.status-bar-text {
  font-size: 0.75rem;
}
</style>
