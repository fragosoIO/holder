import { defineStore } from 'pinia'

export const useChrome = defineStore('chrome', {
  state: () => ({
    newTask: false,
    sidebar: false,
    tick: 0,
    crumb: '',
  }),
  actions: {
    openTask() {
      this.newTask = true
    },
    bump() {
      this.tick += 1
    },
  },
})
