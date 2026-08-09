<template>
    <a class="pth-store-btn" :href="href">
        <svg class="pth-store-btn__icon" viewBox="0 0 24 24" fill="none" stroke="#fff"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M12 3v12" />
            <path d="M7 10l5 5 5-5" />
            <path d="M4 20h16" />
        </svg>
        <span class="pth-store-btn__label">Download PokerTH</span>
        <span class="pth-store-btn__badge">all platforms</span>
    </a>
</template>

<script>
    export default {
        props: {
            href: {
                type: String,
                default: '/app.php/download'
            }
        }
    }
</script>
