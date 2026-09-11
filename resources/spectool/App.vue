<template>
  <!-- Das Live-/Spectator-Tool laeuft nicht mehr hier, sondern in narmods
       Webclient. Wir betten es nur noch ein und geben ihm die Hoehe.

       ?embed=1 schaltet dort Ton per Default aus, unterdrueckt den
       Install-Prompt und blendet (via data-framed) den Fullscreen-Button aus.
       Eine Hoehe meldet der Client bewusst nicht zurueck: /live fuellt seinen
       Viewport und scrollt intern, es gibt also keine Inhaltshoehe – der Host
       gibt die Hoehe vor. Genau das passiert hier. -->
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

// Der Client scrollt intern, die Hoehe bestimmt also nur, wieviel man auf
// einmal sieht: soviel Viewport wie da ist, abzueglich Forenkopf und Rand.
const CHROME_HEIGHT = 140
const MIN_HEIGHT = 560
const MAX_HEIGHT = 900

const height = ref(MIN_HEIGHT)

function fit() {
  const available = window.innerHeight - CHROME_HEIGHT
  height.value = Math.round(Math.min(MAX_HEIGHT, Math.max(MIN_HEIGHT, available)))
}

onMounted(() => {
  fit()
  window.addEventListener('resize', fit)
})
onBeforeUnmount(() => window.removeEventListener('resize', fit))
</script>
