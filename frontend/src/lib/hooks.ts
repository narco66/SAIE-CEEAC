import { useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient, type QueryKey } from '@tanstack/react-query'
import { api, type Paginated } from './api'
import { useToast } from '../components/ui'

/**
 * Liste paginée, filtrée et triée côté serveur (UX-03, API-02).
 */
export function useList<T>(key: string, path: string, filters: Record<string, string | number | boolean | undefined> = {}, perPage = 20) {
  const [page, setPage] = useState(1)
  const query = useQuery({
    queryKey: [key, path, filters, page, perPage],
    queryFn: ({ signal }) => api.get<Paginated<T>>(path, { ...filters, page, per_page: perPage }, signal),
    placeholderData: keepPreviousData,
  })
  return { ...query, page, setPage, items: query.data?.data ?? [], meta: query.data?.meta }
}

export function useData<T>(key: QueryKey, path: string, query?: Record<string, string | number | boolean | undefined>, enabled = true) {
  return useQuery({ queryKey: key, queryFn: ({ signal }) => api.get<{ data: T }>(path, query, signal).then((r) => r.data), enabled })
}

/**
 * Mutation qui attend la réponse serveur avant d'annoncer le succès et invalide les listes concernées.
 */
export function useAction<TVars, TResult = unknown>(fn: (vars: TVars) => Promise<TResult>, options: { success?: string; invalidate?: string[]; onSuccess?: (r: TResult) => void } = {}) {
  const client = useQueryClient()
  const toast = useToast()
  return useMutation({
    mutationFn: fn,
    onSuccess: (result) => {
      options.invalidate?.forEach((k) => client.invalidateQueries({ queryKey: [k] }))
      if (options.success) toast.success(options.success)
      options.onSuccess?.(result)
    },
    onError: (e) => toast.error(e),
  })
}
