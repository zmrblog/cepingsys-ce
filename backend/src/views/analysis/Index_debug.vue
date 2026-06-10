async function selectTask(task) {
  currentTask.value = task
  loading.value = true
  try {
    const [vRes, sRes] = await Promise.all([
      request.get(`/statistics/examine/${task.id}/vote-summary`),
      request.get(`/statistics/examine/${task.id}/score-summary`),
    ])
    voteData.value = vRes.data?.data || {}
    scoreData.value = sRes.data?.data || {}
    console.log('=== VOTE DATA ===', JSON.stringify(voteData.value, null, 2))
    console.log('=== SCORE DATA ===', JSON.stringify(scoreData.value, null, 2))
    console.log('=== targets count ===', voteData.value.targets?.length ?? 0)
  } catch (e) {
    console.error(e)
    ElMessage.error('获取数据失败')
  } finally {
    loading.value = false
  }
}