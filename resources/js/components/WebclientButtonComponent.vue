<template>
    <a class="pth-store-btn" :class="{ 'pth-store-btn--cta': cta }" :href="href" target="_blank" rel="noopener">
        <img src="/images/pokerth.svg" alt="PokerTH" class="pth-store-btn__icon" />
        <span class="pth-store-btn__text">
            <span class="pth-store-btn__label">PokerTH Web-Client</span>
            <span v-if="cta" class="pth-store-btn__cta">Play now!</span>
        </span>
        <span class="pth-store-btn__badge">webapp</span>
    </a>
</template>

<script>
    export default {
        props: {
            href: {
                type: String,
                default: 'https://webclient.pokerth.net'
            },
            // Zweite Zeile "Play now!" + hoeherer Button; nur im Teaser-Header.
            cta: {
                type: Boolean,
                default: false
            }
        }
    }
</script>
