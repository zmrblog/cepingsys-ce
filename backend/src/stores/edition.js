import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import request from '@/api/request'

export const useEditionStore = defineStore('edition', () => {
  const edition = ref('社区版')
  const version = ref('')
  const features = ref([])
  const loaded = ref(false)

  const isCommunity = computed(() => edition.value === '社区版')

  async function fetchEdition() {
    try {
      const res = await request.get('/system/edition')
      if (res.code === 200 && res.data) {
        edition.value = res.data.edition || '社区版'
        version.value = res.data.version || ''
        features.value = res.data.features || []
      }
    } catch (e) {
      // 使用默认值
    } finally {
      loaded.value = true
    }
  }

  function hasFeature(key) {
    if (!isCommunity.value) return true
    const f = features.value.find(f => f.key === key)
    return f ? f.ce === true : false
  }

  /**
   * 获取功能的社区版说明（如 "≤50人"、）
   */
  function getFeatureCeInfo(key) {
    const f = features.value.find(f => f.key === key)
    return f ? f.ce : null
  }

  /**
   * 获取功能的完整信息
   */
  function getFeature(key) {
    return features.value.find(f => f.key === key) || null
  }

  return { edition, version, features, loaded, isCommunity, fetchEdition, hasFeature, getFeatureCeInfo, getFeature }
})
