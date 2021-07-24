<template>
  <div class="a-s" v-if="adverts">
    <!--googleoff: all-->
    <!--noindex-->
    <el-row v-for="(advert) in adverts" :key="advert.id">
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
    this.getAdverts(this.position);
  },
  methods: {
    getAdverts: function (position) {
      axios
        .get("/pthranking/a/" + position)
        .then((res) => {
          if(res.data.success === true){
            this.adverts = res.data.adverts.length ? res.data.adverts : false;
          }

          console.log(this.adverts);
        })
        .catch((err) => {
          console.log(err);
          this.adverts = false;
        });
    },
  },
};
</script>