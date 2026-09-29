import { defineStore } from 'pinia'
import { api, ApiError } from '../api'
import type { Company, User } from '../types'

export const useBoard = defineStore('board', {
  state: () => ({
    mode: 'local',
    user: null as User | null,
    companies: [] as Company[],
    companyId: localStorage.getItem('holder.company') ?? '',
    ready: false,
    unauthenticated: false,
  }),
  getters: {
    company(state): Company | undefined {
      return state.companies.find((item) => item.id === state.companyId) ?? state.companies[0]
    },
  },
  actions: {
    async load() {
      try {
        const data = await api<{ mode: string; user: User; companies: Company[] }>('/api/v1/session')
        this.mode = data.mode
        this.user = data.user
        this.companies = data.companies
        this.unauthenticated = false
        if (!this.companies.some((item) => item.id === this.companyId)) {
          this.companyId = this.companies[0]?.id ?? ''
        }
        localStorage.setItem('holder.company', this.companyId)
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
          this.unauthenticated = true
          this.user = null
        } else {
          throw error
        }
      } finally {
        this.ready = true
      }
    },
    async login(email: string, password: string) {
      const data = await api<{ mode: string; user: User; companies: Company[] }>('/api/v1/session', {
        method: 'POST',
        body: JSON.stringify({ email, password }),
      })
      this.mode = data.mode
      this.user = data.user
      this.companies = data.companies
      this.companyId = this.companies[0]?.id ?? ''
      this.unauthenticated = false
      localStorage.setItem('holder.company', this.companyId)
    },
    select(id: string) {
      this.companyId = id
      localStorage.setItem('holder.company', id)
    },
    async logout() {
      await api('/api/v1/session', { method: 'DELETE' })
      this.user = null
      this.companies = []
      this.companyId = ''
      this.unauthenticated = true
      localStorage.removeItem('holder.company')
    },
  },
})
