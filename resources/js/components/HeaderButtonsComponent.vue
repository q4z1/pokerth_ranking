<template>
    <div class="pth-header-card">
        <div class="pth-header-buttons">
            <download-button-component :href="downloadUrl"></download-button-component>
            <webclient-button-component :href="webclientUrl"></webclient-button-component>
        </div>
    </div>
</template>

<script>
    export default {
        props: {
            webclientUrl: {
                type: String,
                default: 'https://webclient.pokerth.net'
            },
            downloadUrl: {
                type: String,
                default: '/app.php/download'
            }
        }
    }
</script>
