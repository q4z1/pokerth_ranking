<template>
  <div class="px-6 py-8">
    <div class="rounded-t-lg px-6 py-3" style="background: linear-gradient(to right, var(--pth-grad-start), var(--pth-grad-end));">
      <div class="login-header text-white font-semibold">Login</div>
    </div>
    <div class="bg-pth-surface border-x border-b border-pth-border rounded-b-lg p-6 space-y-4">
      <!-- Guest-only mode: set GUEST_ONLY to false to show registered login -->
      <template v-if="!GUEST_ONLY">
        <div class="flex gap-4">
          <label class="flex items-center gap-2 text-pth-text cursor-pointer">
            <input type="radio" v-model="loginMode" value="guest" class="accent-pth-gold" />
            Guest
          </label>
          <label class="flex items-center gap-2 text-pth-text cursor-pointer">
            <input type="radio" v-model="loginMode" value="registered" class="accent-pth-gold" />
            Registered
          </label>
        </div>

        <div v-if="loginMode === 'registered'" class="space-y-3">
          <input
            v-model="username"
            type="text"
            placeholder="Username"
            class="w-full px-3 py-2 rounded bg-pth-surface text-pth-text border border-pth-border focus:border-pth-gold focus:outline-none"
          />
          <input
            v-model="password"
            type="password"
            placeholder="Password"
            autocomplete="off"
            class="w-full px-3 py-2 rounded bg-pth-surface text-pth-text border border-pth-border focus:border-pth-gold focus:outline-none"
            @keydown.enter="doLogin"
          />
        </div>
      </template>

      <p class="login-hint text-pth-text-secondary italic">Login may take some time, please be patient ...</p>

      <div class="flex items-center gap-4 mt-3">
        <button
          :disabled="connecting"
          class="px-4 py-2 rounded text-base font-semibold transition-colors cursor-pointer bg-pth-accent hover:bg-pth-accent-hover text-white disabled:opacity-50"
          @click="doLogin"
        >
          {{ connecting ? 'Connecting ...' : 'Login' }}
        </button>
      </div>

      <a
        v-if="!GUEST_ONLY"
        href="https://pokerth.net/ucp.php?mode=register"
        target="_blank"
        rel="noopener"
        class="block text-pth-accent text-sm hover:underline"
      >
        Create your PokerTH gaming account ...
      </a>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import { connect } from '@/services/netEventHandler'
import { useGameCacheStore } from '@/stores'
const store = useGameCacheStore()

// Set to false to show Guest/Registered radio + username/password fields
const GUEST_ONLY = true

const loginMode = ref('guest')
const username = ref('')
const password = ref('')
const connecting = ref(false)

// Reset login button when connection fails (popup appears) or disconnects
watch(() => store.popup, (p) => {
  if (p && (p.type === 'disconnect' || p.type === 'error')) {
    connecting.value = false
  }
})

watch(() => store.connected, (val) => {
  if (!val) connecting.value = false
})

function doLogin() {
  if (connecting.value) return
  connecting.value = true
  if (loginMode.value === 'registered' && username.value) {
    connect(username.value, password.value)
  } else {
    connect()
  }
  // NetEventHandler will update store.connected and emit events
}
</script>

<style scoped>
.login-header {
  font-size: 1rem;
}
.login-hint {
  font-size: 0.875rem;
}
</style>
