import { act } from 'react'
import { beforeEach, describe, expect, it } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { CookingFlowProvider } from '../../context/CookingFlowContext'
import {
  createQueryWrapper,
  makeAxiosError,
  makeAxiosResponse,
  setApiAdapter,
} from '../../test/queryTestUtils'
import { mockApiAdapter } from '../../test/apiMock'
import { paginatedEnvelope, recipeSummaryDto } from '../../test/queryFixtures'
import type { SavedRecipeDto } from '../../types/apiUserRecipes'
import { SavedRecipesScreen } from './SavedRecipesScreen'

function savedDto(
  id: number,
  name: string,
  categoryName: string,
  recipeType: string,
): SavedRecipeDto {
  return {
    id,
    recipe_id: id,
    recipe: {
      ...recipeSummaryDto,
      id,
      name,
      recipe_type: recipeType,
      recipe_category: {
        id: 5,
        name: categoryName,
        description: null,
      },
    },
    created_at: '2026-11-21T08:00:00.000000Z',
    updated_at: '2026-11-21T08:00:00.000000Z',
  }
}

function renderScreen() {
  return render(
    <MemoryRouter initialEntries={['/saved']}>
      <CookingFlowProvider>
        <Routes>
          <Route element={<SavedRecipesScreen />} path="/saved" />
          <Route element={<p>Search destination</p>} path="/search" />
          <Route
            element={<p>Recipe destination</p>}
            path="/recipes/:recipeId"
          />
        </Routes>
      </CookingFlowProvider>
    </MemoryRouter>,
    { wrapper: createQueryWrapper() },
  )
}

function mockSavedApi(items: SavedRecipeDto[]) {
  const stored = [...items]

  setApiAdapter(async (config) => {
    const method = config.method?.toLowerCase() ?? 'get'
    const url = config.url ?? ''

    if (method === 'get' && url === '/saved-recipes') {
      return makeAxiosResponse(config, paginatedEnvelope(stored))
    }

    if (method === 'delete' && url.startsWith('/saved-recipes/')) {
      const recipeId = Number(url.split('/').pop())
      const index = stored.findIndex((item) => item.recipe_id === recipeId)
      if (index >= 0) {
        stored.splice(index, 1)
      }
      return makeAxiosResponse(config, {
        success: true,
        data: null,
        meta: { api_version: 'v1' },
      })
    }

    return mockApiAdapter(config)
  })

  return stored
}

describe('SavedRecipesScreen', () => {
  beforeEach(() => {
    sessionStorage.clear()
  })

  it('shows category filters, an empty state and bottom navigation', async () => {
    mockSavedApi([])
    renderScreen()

    expect(
      await screen.findByText('No saved recipes yet'),
    ).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'All' })).toHaveAttribute(
      'aria-pressed',
      'true',
    )
    for (const label of ['Curries', 'Sambols', 'Others']) {
      expect(screen.getByRole('button', { name: label })).toBeInTheDocument()
    }
    expect(
      screen.getByRole('navigation', { name: 'Primary navigation' }),
    ).toBeInTheDocument()
  })

  it('loads saved recipes from Laravel and filters them by category', async () => {
    const user = userEvent.setup()
    mockSavedApi([
      savedDto(11, 'Parippu Curry', 'Curry', 'Dhal curry'),
      savedDto(12, 'Pol Sambol', 'Sambol', 'Sambol'),
      savedDto(13, 'Coconut Roti', 'Roti', 'Bread'),
    ])
    renderScreen()

    expect(await screen.findByText('Parippu Curry')).toBeInTheDocument()
    expect(screen.getByText('Pol Sambol')).toBeInTheDocument()
    expect(screen.getByText('Coconut Roti')).toBeInTheDocument()
    expect(
      screen.getAllByRole('button', {
        name: /^Remove .* from favourites$/,
      }),
    ).toHaveLength(3)

    await user.click(screen.getByRole('button', { name: 'Sambols' }))
    expect(screen.getByText('Pol Sambol')).toBeInTheDocument()
    expect(screen.queryByText('Parippu Curry')).not.toBeInTheDocument()
    expect(screen.queryByText('Coconut Roti')).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Others' }))
    expect(screen.getByText('Coconut Roti')).toBeInTheDocument()
    expect(screen.queryByText('Pol Sambol')).not.toBeInTheDocument()
  })

  it('removes a saved recipe through the API', async () => {
    const user = userEvent.setup()
    mockSavedApi([
      savedDto(11, 'Parippu Curry', 'Curry', 'Dhal curry'),
      savedDto(12, 'Pol Sambol', 'Sambol', 'Sambol'),
    ])
    renderScreen()

    expect(await screen.findByText('Pol Sambol')).toBeInTheDocument()

    await user.click(
      screen.getByRole('button', {
        name: 'Remove Pol Sambol from favourites',
      }),
    )

    await waitFor(() => {
      expect(screen.queryByText('Pol Sambol')).not.toBeInTheDocument()
    })
    expect(screen.getByText('Parippu Curry')).toBeInTheDocument()
    expect(
      screen.getByText('Pol Sambol removed from saved recipes.'),
    ).toBeInTheDocument()
  })

  it('shows an error with a working retry action', async () => {
    const user = userEvent.setup()
    let shouldFail = true

    setApiAdapter(async (config) => {
      if (config.url === '/saved-recipes' && shouldFail) {
        shouldFail = false
        throw makeAxiosError(
          config,
          500,
          'INTERNAL_SERVER_ERROR',
          'Saved recipes are temporarily unavailable.',
        )
      }
      if (config.url === '/saved-recipes') {
        shouldFail = false
        return makeAxiosResponse(config, paginatedEnvelope([]))
      }
      return mockApiAdapter(config)
    })

    renderScreen()

    expect(
      await screen.findByText('Saved recipes could not load'),
    ).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Try again' }))

    expect(
      await screen.findByText('No saved recipes yet'),
    ).toBeInTheDocument()
  })

  it('shows a loading state while the first page is in flight', async () => {
    let releaseRequest: (() => void) | undefined

    setApiAdapter(async (config) => {
      if (config.url === '/saved-recipes') {
        await new Promise<void>((resolve) => {
          releaseRequest = resolve
        })
        return makeAxiosResponse(config, paginatedEnvelope([]))
      }
      return mockApiAdapter(config)
    })

    renderScreen()

    expect(
      screen.getByText('Loading saved recipes...'),
    ).toBeInTheDocument()

    await act(async () => {
      releaseRequest?.()
    })

    expect(
      await screen.findByText('No saved recipes yet'),
    ).toBeInTheDocument()
  })
})
