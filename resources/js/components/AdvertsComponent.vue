<template>
  <div class="a-s" v-if="adverts">
    <!--googleoff: all-->
    <!--noindex-->
    <el-row v-for="advert in adverts" :key="advert.id">
      <p>advert</p>
      <el-col>
        <el-row class="noindex robots-nocontent robots-noindex">
          <el-col v-html="advert.content"></el-col>
        </el-row>
        <el-row>
          <el-col><hr /></el-col>
        </el-row>
      </el-col>
    </el-row>
    <!--/noindex-->
    <!--googleon: all-->
  </div>
</template>
<script>
export default {
  props: ["position"],
  data: function () {
    return {
      adverts: false,
    };
  },
  mounted() {
    if (this.isAllowed()) this.getAdverts(this.position);
  },
  methods: {
    getAdverts: function (position) {
      axios
        .get("/pthranking/a/" + position)
        .then((res) => {
          if (res.data.success === true) {
            this.adverts = res.data.adverts.length ? res.data.adverts : false;
          }
        })
        .catch((err) => {
          console.log(err);
          this.adverts = false;
        });
    },
    isAllowed: function () {
      let allowed = false;
      let path = location.pathname;
      if (
        (this.position === "home" && path === "/") ||
        path.indexOf("/index.php", 0) == 0
      )
        allowed = true;
      return allowed;
    },
  },
};
</script>