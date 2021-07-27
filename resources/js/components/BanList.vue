<template>
  <div class="banlist">
    <el-row>
      <el-col :span="20"><h3>Banlist</h3></el-col>
      <el-col :span="4" class="add">
        <el-tooltip content="Add Player to Banlist" placement="left-start">
          <i
            type="primary"
            @click="addPlayer"
            class="el-icon-plus el-icon--primary"
          ></i>
        </el-tooltip>
      </el-col>
    </el-row>
    <transition name="el-fade-in-linear">
      <el-row v-if="showAdd" class="add">
        <el-col>
          <el-autocomplete
            class="inline-input"
            v-model="nickname"
            :fetch-suggestions="querySearch"
            placeholder="Nickname"
            @select="handleNickSelect"
          ></el-autocomplete>
          <el-button
            icon="el-icon-check"
            :disabled="!enabled"
            @click="ban"
            type="primary"
            >Ban</el-button
          >
        </el-col>
      </el-row>
    </transition>
    <el-table v-if="banlist" :data="banlist" stripe style="width: 100%">
      <el-table-column prop="player_id" label="ID"> </el-table-column>
      <el-table-column prop="username" label="Nick"> </el-table-column>
      <el-table-column prop="last_login" label="Last Login"> </el-table-column>
      <el-table-column prop="created" label="Created"> </el-table-column>
      <el-table-column prop="action" label="Action">
        <template slot-scope="scope">
          <el-row>
            <el-col>
              <el-button
                size="small"
                @click="unbanPlayer(scope.row.player_id)"
                type="success"
                >Unban</el-button
              >
              <el-button
                size="small"
                @click="deletePlayer(scope.row.player_id)"
                type="danger"
                >Delete</el-button
              >
            </el-col>
          </el-row>
        </template>
      </el-table-column>
    </el-table>
  </div>
</template>
<script>
export default {
  data() {
    return {
      banlist: false,
      showAdd: false,
      nickname: "",
      enabled: false,
      banPlayer: false,
    };
  },
  mounted() {
    this.getBanlist();
  },
  methods: {
    getBanlist() {
      axios
        .get("/pthranking/banlist")
        .then((res) => {
          if (res.data.success === true && res.data.list.length) {
            this.banlist = res.data.list;
          } else {
            this.notice("No banned players found.", "warning");
          }
        })
        .catch((err) => {
          console.log(err);
        });
    },
    notice(msg, type = "success") {
      this.$message({ message: msg, type: type, offset: 75 });
    },
    deletePlayer(id) {
      this.$confirm(
        "This is a permanent deletion - it will also remove any Forum-DB entries. Continue?",
        "Warning",
        {
          confirmButtonText: "OK",
          cancelButtonText: "Cancel",
          type: "warning",
        }
      )
        .then(() => {
          let queryInfo = new FormData();
          queryInfo.append("action", "delete");
          axios
            .post("/pthranking/banlist/" + id, queryInfo)
            .then((res) => {
              if (res.data.success) {
                this.$message({
                  type: "success",
                  message: "Player deleted.",
                });
                for (const index in this.banlist) {
                  if (this.banlist[index].player_id === id)
                    this.banlist.splice(index, 1);
                }
              } else {
                this.$message({
                  type: "danger",
                  message: "Player deletion failed.",
                });
              }
            })
            .catch((err) => {
              console.log(err);
            });
        })
        .catch((err) => {
          console.log(err);
          this.$message({
            type: "info",
            message: "Deletion canceled.",
          });
        });
    },
    unbanPlayer(id) {
      let queryInfo = new FormData();
      queryInfo.append("action", "unban");
      axios
        .post("/pthranking/banlist/" + id, queryInfo)
        .then((res) => {
          if (res.data.success) {
            this.$message({
              type: "success",
              message: "Player unbanned.",
            });
            for (const index in this.banlist) {
              if (this.banlist[index].player_id === id)
                this.banlist.splice(index, 1);
            }
          } else {
            this.$message({
              type: "danger",
              message: "Player unban failed.",
            });
          }
        })
        .catch((err) => {
          console.log(err);
        });
    },
    addPlayer() {
      this.showAdd = !this.showAdd;
    },
    async querySearch(queryString, cb) {
      if (queryString !== "" && queryString.length > 1) {
        let queryInfo = new FormData();
        queryInfo.append("username", queryString);
        const res = await axios.post("/pthranking/player/search", queryInfo);
        if (
          typeof res.data.success !== "undefined" &&
          res.data.success === true
        ) {
          cb(
            res.data.players.map((player) => {
              player.value = player.username;
              return player;
            })
          );
        } else {
          cp([]);
        }
      }
    },
    handleNickSelect(item) {
      this.enabled = true;
      this.banPlayer = item.player_id;
    },
    ban() {
      let queryInfo = new FormData();
      queryInfo.append("action", "ban");
      axios
        .post("/pthranking/banlist/" + this.banPlayer, queryInfo)
        .then((res) => {
          if (res.data.success) {
            this.showAdd = false;
            this.$message({
              type: "success",
              message: "Player added to Banlist.",
            });
            this.getBanlist();
          } else {
            this.$message({
              type: "danger",
              message: "Player not banned.",
            });
          }
        })
        .catch((err) => {
          console.log(err);
        });
    },
  },
};
</script>
<style lang="scss" scoped>
.banlist {
  .add {
    display: flex;
    justify-content: flex-end;
    align-items: flex-end;
    margin-block-start: 1em;
    .el-icon-plus {
      font-size: 2em;
      font-weight: bold;
      cursor: pointer;
    }
    .el-col {
      display: flex;
      justify-content: flex-end;
      padding: 0.5em;
      margin-bottom: 1em;
      .el-button {
        margin-left: 1em;
      }
    }
  }
}
</style>
