import { createApp } from 'vue'
import Vue3Toastify from 'vue3-toastify'
import 'vue3-toastify/dist/index.css'
import './style.css'
import App from './App.vue'
import { toastDefaults } from './notify.js'

createApp(App)
  .use(Vue3Toastify, toastDefaults)
  .mount('#app')
