export interface ApiPagination {
  current_page: number
  per_page: number
  total: number
  last_page: number
  from: number | null
  to: number | null
}

export interface ApiLinks {
  first: string | null
  last: string | null
  prev: string | null
  next: string | null
}

export interface ApiMeta {
  api_version: string
}

export interface PaginatedApiMeta extends ApiMeta {
  pagination: ApiPagination
}

export interface ApiEnvelope<T> {
  success: boolean
  data: T
  meta: ApiMeta
}

export interface PaginatedApiEnvelope<T> {
  success: boolean
  data: T[]
  links: ApiLinks
  meta: PaginatedApiMeta
}

export interface ApiItemResult<T> {
  data: T
  meta: ApiMeta
}

export interface ApiListResult<T> {
  data: T[]
  links: ApiLinks
  pagination: ApiPagination
  meta: PaginatedApiMeta
}

export interface ApiErrorDetails {
  [field: string]: string[]
}
