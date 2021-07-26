<template>
  <div class="base">
    <el-container>
      <el-header>
        <el-row v-if="auth">
          <el-col>
            <el-menu
              :default-active="activeIndex"
              mode="horizontal"
              @select="handleSelect"
            >
              <el-menu-item class="logo"
                ><img
                  src="/images/pokerth-template-logo_light.png"
                  alt="PokerTH"
              /></el-menu-item>
              <el-submenu index="1">
                <template slot="title">Reports</template>
                <el-menu-item index="1-1">Avatar Reports</el-menu-item>
                <el-menu-item index="1-2">Gametable Name Reports</el-menu-item>
              </el-submenu>
              <el-menu-item index="2">Banlist</el-menu-item>
              <el-menu-item class="auth"
                ><el-row
                  ><el-col
                    ><el-button size="medium">Logout</el-button></el-col
                  ></el-row
                ></el-menu-item
              >
            </el-menu>
          </el-col>
        </el-row>
        <el-row v-else>
          <el-col>
            <el-menu mode="horizontal">
              <el-menu-item class="logo"
                ><img
                  src="/images/pokerth-template-logo_light.png"
                  alt="PokerTH"
              /></el-menu-item>
              <el-menu-item class="auth">
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
              </el-menu-item>
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
          <el-col><h3>Content</h3></el-col>
        </el-row>
      </el-main>
    </el-container>
  </div>
</template>
<script>
export default {
  data() {
    return {
      auth: false,
      username: null,
      password: null,
      activeIndex: "1-1",
    };
  },
  mounted() {},
  methods: {
    login() {
      if (
        this.username === null ||
        this.password === null ||
        this.password.length < 1 ||
        this.username.length < 3
      ) {
        this.notice('Username and/or Password too short', 'error');
      }else{
        this.notice('Logging in ...', 'warning');
      }
    },
    notice(msg, type = 'success') {
      this.$message({ message: msg, type: type, offset: 75 });
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
    margin-left: 20px;
    padding: 0;
    background-color: transparent !important;
    cursor: default;
    &:hover,
    &:focus {
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
    background-color: transparent !important;
    cursor: default;
    &:hover,
    &:focus {
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
        display: none;
      }
      justify-content: flex-end;
    }
  }
}
</style>