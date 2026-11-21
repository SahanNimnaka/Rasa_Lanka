import { act } from 'react'
import { beforeEach, describe, expect, it } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { AppStateProvider } from '../../context/AppStateContext'
import { formatViewedAt } from '../../services/appStateService'
import { mockApiAdapter } from '../../test/apiMock'
import { paginatedEnvelope, recipeSummaryDto } from '../../test/queryFixtures'
import {
  createQueryWrapper,
  makeAxiosError,
  makeAxiosResponse,
  setApiAdapter,
} from '../../test/queryTestUtils'
import type { RecipeViewDto } from '../../types/apiUserRecipes'
import { HistoryScreen } from './HistoryScreen'

const newestViewedAt = new Date(Date.now() - 60_000).toISOString()
const olderViewedAt = new Date(Date.now() - 86_400_000).toISOString()

function viewDto(
  id: number,
  name: string,
  viewedAt: string,
): RecipeViewDto {
  return {
    id,
    recipe_id: id,
    recipe: {
      ...recipeSummaryDto,
      id,
      name,
    },
    viewed_at: viewedAt,
    created_at: viewedAt,
    updated_at: viewedAt,
  }
}

function renderScreen() {
  return render(
    <MemoryRouter initialEntries={['/history']}>
      <AppStateProvider>
        <Routes>
          <Route element={<HistoryScreen />} path="/history" />
          <Route
            element={<p>Recipe destination</p>}
            path="/recipes/:recipeId"
          />
        </Routes>
      </AppStateProvider>
    </MemoryRouter>,
    { wrapper: createQueryWrapper() },
  )
}

function mockHistoryApi(items: RecipeViewDto[]) {
  const stored = [...items]

  setApiAdapter(async (config) => {
    const method = config.method?.toLowerCase() ?? 'get'
    const url = config.url ?? ''

    if (method === 'get' && url === '/recipe-history') {
      return makeAxiosResponse(config, paginatedEnvelope(stored))
    }

    if (method === 'delete' && url === '/recipe-history') {
      stored.splice(0, stored.length)
      return makeAxiosResponse(config, {
        success: true,
        data: { deleted_count: items.length },
        meta: { api_version: 'v1' },
      })
    }

    return mockApiAdapter(config)
  })

  return stored
}

describe('HistoryScreen', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('loads and displays API-backed viewing history in order', async () => {
    mockHistoryApi([
      viewDto(11, 'Parippu Curry', newestViewedAt),
      viewDto(12, 'Pol Sambol', olderViewedAt),
    ])
    renderScreen()

    expect(
      await screen.findByRole('heading', { name: 'History' }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole('heading', { name: 'Recently Viewed' }),
    ).toBeInTheDocument()
    expect(screen.getByText('Recipes you explored recently.')).toBeInTheDocument()

    expect(await screen.findByText('Parippu Curry')).toBeInTheDocument()
    expect(screen.getByText('Pol Sambol')).toBeInTheDocument()

    const entries = screen.getAllByRole('button', {
      name: /Parippu Curry|Pol Sambol/,
    })
    expect(entries[0]).toHaveTextContent('Parippu Curry')
    expect(entries[0]).toHaveTextContent(formatViewedAt(newestViewedAt))
    expect(entries[1]).toHaveTextContent('Pol Sambol')
    expect(entries[1]).toHaveTextContent(formatViewedAt(olderViewedAt))
  })

  it('confirms deletion and clears history through the API', async () => {
    const user = userEvent.setup()
    mockHistoryApi([viewDto(11, 'Parippu Curry', newestViewedAt)])
    renderScreen()

    expect(await screen.findByText('Parippu Curry')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Delete History' }))
    expect(screen.getByText('Delete all history?')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Clear history' }))

    expect(await screen.findByText('Your history is empty')).toBeInTheDocument()
    expect(screen.getByText('History cleared.')).toBeInTheDocument()
    expect(screen.queryByText('Parippu Curry')).not.toBeInTheDocument()
  })

  it('can cancel the deletion confirmation', async () => {
    const user = userEvent.setup()
    mockHistoryApi([viewDto(11, 'Parippu Curry', newestViewedAt)])
    renderScreen()

    expect(await screen.findByText('Parippu Curry')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Delete History' }))
    await user.click(screen.getByRole('button', { name: 'Cancel' }))

    expect(
      screen.queryByText('Delete all history?'),
    ).not.toBeInTheDocument()
    expect(screen.getByText('Parippu Curry')).toBeInTheDocument()
  })

  it('shows an error with a working retry action', async () => {
    const user = userEvent.setup()
    let shouldFail = true

    setApiAdapter(async (config) => {
      if (config.url === '/recipe-history' && config.method === 'get') {
        if (shouldFail) {
          shouldFail = false
          throw makeAxiosError(
            config,
            500,
            'INTERNAL_SERVER_ERROR',
            'History is temporarily unavailable.',
          )
        }
        return makeAxiosResponse(config, paginatedEnvelope([]))
      }
      return mockApiAdapter(config)
    })

    renderScreen()

    expect(await screen.findByText('History could not load')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Try again' }))

    expect(await screen.findByText('Your history is empty')).toBeInTheDocument()
  })

  it('shows a loading state while the first page is in flight', async () => {
    let releaseRequest: (() => void) | undefined

    setApiAdapter(async (config) => {
      if (config.url === '/recipe-history' && config.method === 'get') {
        await new Promise<void>((resolve) => {
          releaseRequest = resolve
        })
        return makeAxiosResponse(config, paginatedEnvelope([]))
      }
      return mockApiAdapter(config)
    })

    renderScreen()

    expect(screen.getByText('Loading recipe history...')).toBeInTheDocument()

    await act(async () => {
      releaseRequest?.()
    })

    expect(await screen.findByText('Your history is empty')).toBeInTheDocument()
  })

  it('loads additional pages when requested', async () => {
    const user = userEvent.setup()
    const pageOne = paginatedEnvelope([viewDto(11, 'Parippu Curry', newestViewedAt)])
    pageOne.links.next = 'http://localhost/api/v1/recipe-history?page=2'
    pageOne.meta.pagination.last_page = 2

    const pageTwo = paginatedEnvelope([viewDto(12, 'Pol Sambol', olderViewedAt)])
    pageTwo.meta.pagination.current_page = 2

    setApiAdapter(async (config) => {
      if (config.url === '/recipe-history' && config.method === 'get') {
        const page = Number((config.params as { page?: number } | undefined)?.page ?? 1)
        return makeAxiosResponse(config, page === 1 ? pageOne : pageTwo)
      }
      return mockApiAdapter(config)
    })

    renderScreen()

    expect(await screen.findByText('Parippu Curry')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Load more' }))

    await waitFor(() => {
      expect(screen.getByText('Pol Sambol')).toBeInTheDocument()
    })
    expect(screen.getByText('Parippu Curry')).toBeInTheDocument()
  })
})
