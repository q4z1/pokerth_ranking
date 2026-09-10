<template>
  <!-- Das Live-/Spectator-Tool laeuft nicht mehr hier, sondern in narmods
       Webclient. Wir betten es nur noch ein und geben ihm die Hoehe.

       Bewusst OHNE ?embed=1: in dessen Embed-Modus nimmt das CSS der
       Lobby-Liste (#live-lobby-list) ihr overflow-y:auto weg, weil die Seite
       dort in voller Hoehe wachsen und der Host das iframe nachziehen soll.
       Der Hoehen-Handshake dafuer misst aber documentElement, das in Live-CSS
       auf 100% + overflow:hidden geklemmt ist – gemeldet wird also immer
       exakt die Hoehe, die wir gerade gesetzt haben. Ergebnis: das iframe
       waechst nie, und die Spielerliste ist abgeschnitten und nicht
       scrollbar. Ohne embed=1 behaelt die Liste ihren eigenen Scroller. -->
  <iframe
    id="pth-live"
    src="https://webclient.pokerth.net/live"
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
