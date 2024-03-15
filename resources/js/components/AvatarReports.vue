
<template>
  <div class="areports">
    <el-row>
      <el-col :span="20"><h3>Avatar Reports</h3></el-col>
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
      <el-table-column prop="avatar" label="Avatar">
        <template slot-scope="scope">
          <el-row>
            <el-col><img :src="'/images/avatars/game/' + scope.row.avatar_hash + '.' + scope.row.avatar_type" :alt="'Owner: ' + scope.row.creator.username"></el-col>
          </el-row>
        </template>
      </el-table-column>
      <el-table-column prop="reporter" label="Reporter">
        <template slot-scope="scope">
          <el-row>
            <el-col>
              <span v-if="scope.row.reporter !== null">{{ scope.row.reporter.username }}</span>
            </el-col>
          </el-row>
        </template>
      </el-table-column>
      <el-table-column prop="tmestamp" label="Date"> </el-table-column>
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
        .get("/pthranking/reports/avatar")
        .then((res) => {
          if (res.data.success === true && res.data.list.length) {
            this.reports = res.data.list;
          } else {
            this.notice("No reported avatars found.", "warning");
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
