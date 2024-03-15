<template>
  <div v-if="html">
    <div id="home-collapse" v-if="!disabled">
      <a class="close" @click="closeCollapse">x</a>
      <div v-html="html"></div>
    </div>
  </div>
</template>
<script>
export default {
  data() {
    return {
      disabled: false,
      html: false,
    };
  },
  mounted() {
    let p = window.location.pathname;
    if (p === "/" || p.indexOf("index.php") != -1) {
      axios
        .get("/pthranking/html/welcome")
        .then((res) => {
          if (
            typeof res.data.success !== "undefined" &&
            res.data.success === true
          ) {
            if (this.getCookie("closeHomeCollapse") === null) {
              this.html = res.data.html;
              this.disabled = false;
            }
          } else {
            let exp = new Date();
            exp.setTime(exp.getTime() - 1000 * 86400);
            window.document.cookie =
              "closeHomeCollapse=true; expires=" +
              exp.toUTCString() +
              ";path=/";
          }
        })
        .catch((err) => {
          console.log(err);
        });
    }
  },
  methods: {
    closeCollapse(evt) {
      // console.log('closeCollapse evt', evt)
      let exp = new Date();
      exp.setTime(exp.getTime() + 1000 * 86400 * 365);
      window.document.cookie =
        "closeHomeCollapse=true; expires=" + exp.toUTCString() + ";path=/";
      this.disabled = true;
    },
    getCookie(name) {
      let re = new RegExp(name + "=([^;]+)");
      let value = re.exec(window.document.cookie);
      return value != null ? unescape(value[1]) : null;
    },
  },
};
</script>
<style lang="scss">
#home-collapse {
  background-color: #2d3039;
  margin: 1em 0;
  padding: 1em;
  position: relative;
  color: #bec4c9;
  h1,
  h2,
  h3,
  h4,
  h5,
  h6 {
    font-weight: normal;
    padding-bottom: 1.2em;
  }
  a.close {
    position: absolute;
    top: 0.15em;
    right: 0.15em;
    width: 1.2em;
    height: 1.2em;
    font-weight: normal;
    font-size: 1.4em;
    vertical-align: middle;
    cursor: pointer;
    &:hover {
      color: #838b98;
      text-decoration: none;
    }
  }
}
.fd_dark #home-collapse {
  background-color: #242a36;
  color: #838b98;
  border: 1px solid rgba(255, 255, 255, 0.04);
  a.close {
    &:hover {
      color: #bec4c9;
    }
  }
}
</style>