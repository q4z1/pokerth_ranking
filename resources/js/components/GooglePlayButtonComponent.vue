<template>
    <a class="pth-store-btn pth-store-btn--play" :href="href" target="_blank" rel="noopener" aria-label="Get it on Google Play">
        <svg class="pth-store-btn__play-logo" viewBox="0 0 24 26" aria-hidden="true">
            <path fill="#4285F4" d="M1 1v24l12-12z"/>
            <path fill="#34A853" d="M1 1l12 12 3.84-3.36z"/>
            <path fill="#FBBC04" d="M16.84 9.64L23 13l-6.16 3.36L13 13z"/>
            <path fill="#EA4335" d="M1 25l12-12 3.84 3.36z"/>
        </svg>
        <span class="pth-store-btn__text">
            <span class="pth-store-btn__play-small">GET IT ON</span>
            <span class="pth-store-btn__play-big">Google Play</span>
        </span>
    </a>
</template>

<script>
    export default {
        props: {
            href: {
                type: String,
                default: 'https://play.google.com/store/apps/details?id=net.pokerth.PokerTH_QML'
            }
        }
    }
</script>
