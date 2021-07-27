<template>
  <div class="greports">
    <h5>Gamename Reports ...</h5>
  </div>
</template>
<script>
export default {
  data() {
    return {
      auth: false,
    };
  },
  mounted() {
    this.getReports();
  },
  methods: {
    getReports() {
      console.log('fetching gamename reports ...')
    }
  },
}
</script>
