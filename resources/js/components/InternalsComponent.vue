<template>
  <div class="base">
    <el-container>
      <el-header>
        <el-row v-if="auth">
          <el-col>
            <el-menu
              :default-active="activeIndex"
              mode="horizontal"
              @select="navigate"
            >
              <li class="logo">
                <a href="/" title="PokerTH" target="_blank">
                  <img
                    src="/images/pokerth-template-logo_light.png"
                    alt="PokerTH"
                  />
                </a>
              </li>
              <el-menu-item index="1">Adverts</el-menu-item>
              <el-menu-item index="2">Banlist</el-menu-item>
              <el-submenu index="3">
                <template slot="title">Reports</template>
                <el-menu-item index="3-1">Avatar Reports</el-menu-item>
                <el-menu-item index="3-2">Gametable Name Reports</el-menu-item>
              </el-submenu>
              <li class="auth">
                <el-row
                  ><el-col
                    ><el-button size="medium" @click="logout"
                      >Logout</el-button
                    ></el-col
                  ></el-row
                >
              </li>
            </el-menu>
          </el-col>
        </el-row>
        <el-row v-else>
          <el-col>
            <el-menu mode="horizontal">
              <li class="logo">
                <img
                  src="/images/pokerth-template-logo_light.png"
                  alt="PokerTH"
                />
              </li>
              <li class="auth">
                <el-row>
                  <el-col>
                    <el-input
                      placeholder="Username"
                      v-model="username"
                      size="medium"
                      clearable
                    ></el-input>
                  </el-col>
                  <el-col>
                    <el-input
                      placeholder="Password"
                      v-model="password"
                      size="medium"
                      show-password
                      clearable
                    ></el-input>
                  </el-col>
                  <el-col>
                    <el-button size="medium" @click="login">Login</el-button>
                  </el-col>
                </el-row>
              </li>
            </el-menu>
          </el-col>
        </el-row>
      </el-header>
      <el-main>
        <el-row v-if="!auth">
          <el-col>
            <h2>PokerTH Internals</h2>
            <i>Please login...</i>
          </el-col>
        </el-row>
        <el-row v-else>
          <el-col>
            <adverts v-if="activeIndex === '1'"></adverts>
            <ban-list v-if="activeIndex === '2'"></ban-list>
            <avatar-reports v-if="activeIndex === '3-1'"></avatar-reports>
            <game-name-reports v-if="activeIndex === '3-2'"></game-name-reports>
          </el-col>
        </el-row>
      </el-main>
    </el-container>
  </div>
</template>
<script>
export default {
  props: ["authenticated"],
  data() {
    return {
      auth: false,
      username: null,
      password: null,
      activeIndex: "2",
    };
  },
  mounted() {
    this.auth = this.authenticated;
  },
  methods: {
    login() {
      if (
        this.username === null ||
        this.password === null ||
        this.password.length < 1 ||
        this.username.length < 3
      ) {
        this.notice("Username and/or Password too short", "error");
      } else {
        let queryInfo = new FormData();
        queryInfo.append("username", this.username);
        queryInfo.append("password", this.password);
        axios
          .post("/pthranking/login", queryInfo)
          .then((res) => {
            if (res.data.success === true) {
              this.auth = true;
              this.notice(res.data.msg);
            } else {
              this.notice(res.data.msg, "error");
            }
          })
          .catch((err) => {
            this.notice("Login failed.", "error");
          });
      }
    },
    logout() {
      axios
        .get("/pthranking/logout")
        .then((res) => {
          if (res.data.success === true) {
            this.auth = false;
            this.notice(res.data.msg, "default");
          } else {
            this.notice(res.data.msg, "error");
          }
        })
        .catch((err) => {
          this.notice("Logout failed.", "error");
        });
    },
    notice(msg, type = "success") {
      this.$message({ message: msg, type: type, offset: 75 });
    },
    navigate(index, indexPath) {
      this.activeIndex = index;
    },
  },
};
</script>
<style lang="scss" scoped>
.base {
  .el-menu--horizontal.el-menu {
    display: flex;
  }
  .auth {
    border-bottom: 0;
    margin-top: 10px;
    margin-left: 20px;
    height: 50px;
    background-color: transparent !important;
    cursor: default;
    &:hover,
    &:focus,
    &:click {
      background-color: transparent !important;
    }
    .el-row {
      margin-left: auto;
      .el-col {
        width: auto;
        .el-input {
          width: calc(100% - 10px);
        }
        .el-button {
          margin-right: 20px;
        }
      }
    }
  }
  .logo {
    flex-grow: 1;
    padding-right: 20px;
    border-bottom: 0;
    margin-top: 10px;
    margin-left: 20px;
    height: 50px;
    background-color: transparent !important;
    cursor: default;
    &:hover,
    &:focus,
    &:click {
      background-color: transparent !important;
    }
    img {
      height: 80%;
    }
  }
}
@media only screen and (max-width: 896px) {
  .base {
    .auth {
      .el-row {
        .el-col {
          max-width: 150px;
          .el-input {
            max-width: 140px;
          }
          .el-button {
            padding: 10px;
          }
        }
      }
    }
  }
}
@media only screen and (max-width: 662px) {
  .base {
    .auth {
      .el-row {
        .el-col {
          max-width: 100px;
          .el-input {
            max-width: 90px;
          }
          .el-button {
            padding: 10px 5px;
          }
        }
      }
    }
  }
}
@media only screen and (max-width: 575px) {
  .base {
    .el-menu {
      .logo {
        height: 40px;
        margin-top: 15px;
      }
      justify-content: flex-end;
    }
  }
}
@media only screen and (max-width: 455px) {
  .base {
    .auth {
      margin-left: 20px;
    }
    .el-menu {
      .logo {
        display: none;
      }
    }
  }
}
</style>