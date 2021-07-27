<template>
  <div class="areports">
    <h5>Avatar Reports ...</h5>
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
      console.log('fetching avatar reports ...')
    }
  },
}
</script>
