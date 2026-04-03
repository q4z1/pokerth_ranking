<template>
  <div class="flex flex-col bg-pth-elevated border border-pth-border rounded-lg shadow-xl text-pth-text overflow-hidden">
    <LoginDialog v-if="!connected" />
    <router-view v-else />
    <Teleport to="body">
      <PopupOverlay />
    </Teleport>
  </div>
</template>

<script setup>
import { computed, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useGameCacheStore } from '@/stores'
import { init as initNet } from '@/services/netEventHandler'
import LoginDialog from '@/components/LoginDialog.vue'
import PopupOverlay from '@/components/PopupOverlay.vue'

const store = useGameCacheStore()
const router = useRouter()

// Initialize the network event handler with store and router
initNet(store, router)

const connected = computed(() => store.connected)

// Route based on active view
watch(
  () => store.gameTableActive,
  (isGame) => {
    if (isGame) {
      router.push({ name: 'game' })
    } else if (store.connected) {
      router.push({ name: 'lobby' })
    }
  }
)
</script>
