<script setup>
import { ref, onMounted } from 'vue'

const props = defineProps({ src: String })
const svgContent = ref('')

onMounted(async () => {
  const res = await fetch(props.src)
  svgContent.value = await res.text()
})
</script>

<template>
  <div class="inline-svg" v-html="svgContent" />
</template>
