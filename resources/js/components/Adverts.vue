<template>
  <div class="a-s">
    <el-row>
      <el-col :span="20"><h3>Adverts</h3></el-col>
      <el-col :span="4" class="add">
        <el-tooltip content="Add Advert" placement="left-start">
          <i
            type="primary"
            @click="showAdd = !showAdd"
            class="el-icon-plus el-icon--primary"
          ></i>
        </el-tooltip>
      </el-col>
    </el-row>
    <transition name="el-fade-in-linear">
      <el-row v-if="showAdd" class="add">
        <el-col>

        </el-col>
      </el-row>
    </transition>
    <el-table v-if="adverts" :data="adverts" stripe style="width: 100%">
      <el-table-column prop="id" label="ID"> </el-table-column>
      <el-table-column prop="position" label="Position"> </el-table-column>
      <el-table-column prop="content" label="Content" width="400"> 
        <template slot-scope="scope">
          <el-row>
            <el-col v-html="scope.row.content" class="content">

            </el-col>
          </el-row>
        </template>
      </el-table-column>
      <el-table-column prop="order" label="Order"> </el-table-column>
      <el-table-column prop="start" label="Start"> </el-table-column>
      <el-table-column prop="end" label="End"> </el-table-column>
      <el-table-column prop="action" label="Action">
        <template slot-scope="scope">
          <el-row>
            <el-col>
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
      adverts: false,
      showAdd: false,
    };
  },
  mounted() {
    this.getAdverts();
  },
  methods: {
    getAdverts() {
      axios
        .get("/pthranking/adverts")
        .then((res) => {
          if (res.data.success === true && res.data.list.length) {
            this.adverts = res.data.list;
          } else {
            this.notice("No adverts found.", "warning");
          }
        })
        .catch((err) => {
          console.log(err);
        });
    },
    notice(msg, type = "success") {
      this.$message({ message: msg, type: type, offset: 75 });
    },
  },
};
</script>
<style lang="scss" scoped>
.a-s {
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
  .content{
    height: 180px;
    overflow-y: auto;
  }
}
</style>
