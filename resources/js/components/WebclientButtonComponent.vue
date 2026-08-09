<template>
    <a class="pth-store-btn" :href="href" target="_blank" rel="noopener">
        <img src="/images/pokerth.svg" alt="PokerTH" class="pth-store-btn__icon" />
        <span class="pth-store-btn__label">PokerTH Web-Client</span>
        <span class="pth-store-btn__badge">webapp</span>
    </a>
</template>

<script>
    export default {
        props: {
            href: {
                type: String,
                default: 'https://webclient.pokerth.net'
            }
        }
    }
</script>
