<template>
  <router-view />
</template>

<script setup>
import { onMounted } from 'vue'
import { useEditionStore } from '@/stores/edition'

const editionStore = useEditionStore()

onMounted(() => {
  editionStore.fetchEdition()
})
</script>

<style lang="scss">
html, body {
  margin: 0;
  padding: 0;
  height: 100%;
}

#app {
  height: 100%;
}
</style>
