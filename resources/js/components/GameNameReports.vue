<template>
  <div class="greports">
    <el-row>
      <el-col :span="20"><h3>Gamename Reports</h3></el-col>
    </el-row>
    <el-table v-if="reports" :data="reports" stripe style="width: 100%">
      <el-table-column prop="id" label="ID"> </el-table-column>
      <el-table-column prop="creator" label="Creator">
        <template slot-scope="scope">
          <el-row>
            <el-col>
              <span v-if="scope.row.creator !== null">{{ scope.row.creator.username }}</span>
            </el-col>
          </el-row>
        </template>
      </el-table-column>
      <el-table-column prop="game_name" label="Name"></el-table-column>
      <el-table-column prop="reporter" label="Reporter">
        <template slot-scope="scope">
          <el-row>
            <el-col>
              <span v-if="scope.row.reporter !== null">{{ scope.row.reporter.username }}</span>
            </el-col>
          </el-row>
        </template>
      </el-table-column>
      <el-table-column prop="timestamp" label="Date"> </el-table-column>
      <el-table-column prop="action" label="Action">
        <template slot-scope="scope">
          <el-row>
            <el-col> </el-col>
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
      reports: false,
    };
  },
  mounted() {
    this.getReports();
  },
  methods: {
    getReports() {
      axios
        .get("/pthranking/reports/gamename")
        .then((res) => {
          if (res.data.success === true && res.data.list.length) {
            this.reports = res.data.list;
          } else {
            this.notice("No reported Gamenames found.", "warning");
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
