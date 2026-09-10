<template>
  <!-- Das Live-/Spectator-Tool läuft nicht mehr hier, sondern in narmods
       Webclient (Route /live). Wir betten es nur noch ein; die Höhe meldet
       der Client per postMessage zurück. -->
  <iframe
    id="pth-live"
    src="https://webclient.pokerth.net/live?embed=1"
    allow="autoplay"
    title="PokerTH live"
    :style="{ width: '100%', border: 0, height: height + 'px' }"
  ></iframe>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'

const WEBCLIENT_ORIGIN = 'https://webclient.pokerth.net'

const height = ref(640)

function onMessage(ev) {
  if (ev.origin !== WEBCLIENT_ORIGIN) return

  const d = ev.data
  if (!d || d.channel !== 'pokerth-live') return

  if (d.type === 'height' && Number.isFinite(Number(d.height))) {
    height.value = Number(d.height)
  }
}

onMounted(() => window.addEventListener('message', onMessage))
onBeforeUnmount(() => window.removeEventListener('message', onMessage))
</script>
