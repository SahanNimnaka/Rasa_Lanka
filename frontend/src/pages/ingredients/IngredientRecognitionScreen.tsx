import { useDeferredValue, useId, useMemo, useState } from 'react'
import { Image as ImageIcon, Plus, X } from 'lucide-react'
import { useLocation, useNavigate } from 'react-router-dom'
import { useCookingFlow } from '../../context/cookingFlowStore'
import { IngredientRow } from '../../components/ingredients/IngredientRow'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { ScreenHeader } from '../../components/layout/ScreenHeader'
import { Button } from '../../components/ui/Button'
import { Input } from '../../components/ui/Input'
import { LoadingIndicator } from '../../components/ui/LoadingIndicator'
import { useNavigateBack } from '../../hooks/useNavigateBack'
import { useIngredientCategories } from '../../hooks/queries/useCategories'
import { useIngredient, useIngredients } from '../../hooks/queries/useIngredients'
import type { IngredientViewModel } from '../../services/mappers/ingredientMappers'
import { parseApiId } from '../../services/queries/queryKeys'
import {
  DEMO_RECOGNITION_NOTICE,
  getDemoRecognitionResults,
} from '../../services/recognitionService'
import type {
  IngredientRecognitionState,
  SelectedIngredient,
} from '../../types/ingredients'

function toCatalogueSelection(
  ingredient: IngredientViewModel,
  mappedFromDemoId?: string,
): SelectedIngredient | null {
  const numericId = parseApiId(ingredient.id)
  if (numericId === null) {
    return null
  }

  return {
    id: String(numericId),
    name: ingredient.name,
    source: 'catalogue',
    ...(mappedFromDemoId ? { mappedFromDemoId } : {}),
  }
}

export function IngredientRecognitionScreen() {
  const navigate = useNavigate()
  const location = useLocation()
  const categorySelectId = useId()
  const goBack = useNavigateBack('/ingredients/upload')
  const flow = useCookingFlow()
  const navigationState = location.state as IngredientRecognitionState | null

  const recognized = useMemo(
    () =>
      navigationState?.initialIngredients?.length
        ? navigationState.initialIngredients
        : getDemoRecognitionResults(),
    [navigationState],
  )

  const savedCatalogueIngredients = useMemo(
    () =>
      flow.ingredients.filter(
        (ingredient) => ingredient.source !== 'demo' && parseApiId(ingredient.id) !== null,
      ),
    [flow.ingredients],
  )

  const [selectedIds, setSelectedIds] = useState<Set<string>>(() => {
    const ids = new Set(savedCatalogueIngredients.map((item) => item.id))
    for (const item of flow.ingredients) {
      if (item.mappedFromDemoId && parseApiId(item.id) !== null) {
        ids.add(item.mappedFromDemoId)
        ids.add(item.id)
      }
    }
    return ids
  })
  const [selectedCatalogue, setSelectedCatalogue] = useState<
    SelectedIngredient[]
  >(() =>
    savedCatalogueIngredients.map((item) => ({ ...item, source: 'catalogue' })),
  )
  const [demoMappings, setDemoMappings] = useState<
    Record<string, SelectedIngredient>
  >(() => {
    const mappings: Record<string, SelectedIngredient> = {}
    for (const item of flow.ingredients) {
      if (item.mappedFromDemoId && parseApiId(item.id) !== null) {
        mappings[item.mappedFromDemoId] = {
          ...item,
          source: 'catalogue',
        }
      }
    }
    return mappings
  })

  const [isAddingManual, setIsAddingManual] = useState(false)
  const [mappingTarget, setMappingTarget] = useState<string | null>(null)
  const [search, setSearch] = useState('')
  const deferredSearch = useDeferredValue(search)
  const [categoryId, setCategoryId] = useState('')
  const [page, setPage] = useState(1)
  const [lookupValue, setLookupValue] = useState('')

  const numericCategoryId = categoryId ? parseApiId(categoryId) : null
  const catalogueParams = useMemo(
    () => ({
      page,
      per_page: 10,
      ...(deferredSearch.trim() ? { search: deferredSearch.trim() } : {}),
      ...(numericCategoryId !== null
        ? { ingredient_category_id: numericCategoryId }
        : {}),
    }),
    [deferredSearch, numericCategoryId, page],
  )
  const catalogueQuery = useIngredients(catalogueParams)
  const categoriesQuery = useIngredientCategories({ page: 1, per_page: 100 })

  const lookupId = parseApiId(lookupValue)
  const lookupQuery = useIngredient(lookupId)
  const lookupSelection =
    lookupId !== null &&
    lookupQuery.data !== undefined &&
    String(lookupQuery.data.id) === String(lookupId)
      ? toCatalogueSelection(lookupQuery.data)
      : null

  const showCatalogue = isAddingManual || mappingTarget !== null

  const finalSelection = useMemo(() => {
    const result: SelectedIngredient[] = []
    const seen = new Set<string>()

    const add = (ingredient: SelectedIngredient) => {
      if (parseApiId(ingredient.id) === null || seen.has(ingredient.id)) {
        return
      }
      seen.add(ingredient.id)
      result.push({ ...ingredient, source: 'catalogue' })
    }

    for (const demo of recognized) {
      if (!selectedIds.has(demo.id)) {
        continue
      }
      const mapped = demoMappings[demo.id]
      if (mapped) {
        add(mapped)
      }
    }

    for (const ingredient of selectedCatalogue) {
      if (selectedIds.has(ingredient.id)) {
        add(ingredient)
      }
    }

    return result
  }, [demoMappings, recognized, selectedCatalogue, selectedIds])

  const unmappedSelectedCount = recognized.filter(
    (ingredient) =>
      selectedIds.has(ingredient.id) && demoMappings[ingredient.id] === undefined,
  ).length

  function handleToggle(id: string, isSelected: boolean) {
    setSelectedIds((current) => {
      const next = new Set(current)
      if (isSelected) {
        next.add(id)
      } else {
        next.delete(id)
      }
      return next
    })
  }

  function handleCatalogueToggle(
    ingredient: SelectedIngredient,
    isSelected: boolean,
  ) {
    setSelectedCatalogue((current) => {
      if (!isSelected) {
        return current.filter((item) => item.id !== ingredient.id)
      }
      if (current.some((item) => item.id === ingredient.id)) {
        return current.map((item) =>
          item.id === ingredient.id ? { ...item, ...ingredient } : item,
        )
      }
      return [...current, ingredient]
    })
    handleToggle(ingredient.id, isSelected)
  }

  function handleRemoveCatalogue(id: string) {
    setSelectedCatalogue((current) => current.filter((item) => item.id !== id))
    setSelectedIds((current) => {
      const next = new Set(current)
      next.delete(id)
      return next
    })
  }

  function openMapping(demoId: string) {
    setMappingTarget(demoId)
    setIsAddingManual(false)
    setSearch('')
    setCategoryId('')
    setPage(1)
    setLookupValue('')
  }

  function openManualCatalogue() {
    setIsAddingManual((current) => !current)
    setMappingTarget(null)
    setSearch('')
    setCategoryId('')
    setPage(1)
    setLookupValue('')
  }

  function closeCatalogue() {
    setIsAddingManual(false)
    setMappingTarget(null)
    setLookupValue('')
  }

  function applyMapping(ingredient: SelectedIngredient) {
    const target = mappingTarget
    if (!target || parseApiId(ingredient.id) === null) {
      return
    }

    const mapped: SelectedIngredient = {
      ...ingredient,
      source: 'catalogue',
      mappedFromDemoId: target,
    }
    setDemoMappings((current) => ({ ...current, [target]: mapped }))
    setSelectedCatalogue((current) => {
      if (current.some((item) => item.id === mapped.id)) {
        return current.map((item) =>
          item.id === mapped.id ? { ...item, ...mapped } : item,
        )
      }
      return [...current, mapped]
    })
    setSelectedIds((current) => new Set(current).add(target).add(mapped.id))
    setMappingTarget(null)
    setLookupValue('')
  }

  function handleConfirm() {
    flow.setIngredients(finalSelection)
    navigate('/ingredients/quantities', {
      state: { ingredients: finalSelection },
    })
  }

  return (
    <MobileScreen aria-labelledby="recognition-title">
      <ScreenHeader onBack={goBack} />

      <section className="mt-3">
        <p className="eyebrow-label">Review your ingredients</p>
        <h1
          className="mt-1 text-[1.7rem] font-extrabold"
          id="recognition-title"
        >
          Detected Ingredients
        </h1>

        <div
          aria-label="Demonstration recognition data"
          className="glass-panel mt-4 rounded-xl border border-spice-orange/40 px-3 py-2"
        >
          <p className="text-xs font-bold text-ink">
            Demo data — not a model result
          </p>
          <p className="mt-1 text-xs leading-5 text-muted">
            {DEMO_RECOGNITION_NOTICE} Demo detections are not catalogue
            ingredients until you map them to a Laravel ingredient entry.
          </p>
        </div>

        <div className="mt-5 flex h-[146px] items-center justify-center overflow-hidden rounded-card border border-forest-green/15 bg-warm-cream">
          {navigationState?.image?.dataUrl ? (
            <img
              alt="Uploaded ingredients"
              className="h-full w-full object-contain"
              src={navigationState.image.dataUrl}
            />
          ) : (
            <div className="text-center text-muted">
              <ImageIcon aria-hidden="true" className="mx-auto" size={30} />
              <p className="mt-2 text-sm">Uploaded ingredient image</p>
              <p className="text-xs">No preview available</p>
            </div>
          )}
        </div>

        <div className="mt-6">
          <h2 className="text-base font-bold">
            Review the recognition results
          </h2>
          <p className="mt-1 text-sm text-muted">
            Demo detections are illustrative. Map them to catalogue entries
            before using them.
          </p>

          <div className="mt-4 grid gap-3 md:grid-cols-2 md:gap-5">
            {recognized.map((ingredient) => {
              const mapped = demoMappings[ingredient.id]
              return (
                <div className="grid gap-2" key={ingredient.id}>
                  <IngredientRow
                    action={
                      <Button
                        aria-label={`Map ${ingredient.name} to catalogue`}
                        onClick={() => openMapping(ingredient.id)}
                        size="sm"
                        variant="secondary"
                      >
                        {mapped ? 'Change mapping' : 'Map to catalogue'}
                      </Button>
                    }
                    confidenceLabel="Demo confidence"
                    footer={
                      mapped
                        ? `Mapped to ${mapped.name} (#${mapped.id})`
                        : 'Not mapped — cannot be continued'
                    }
                    ingredient={ingredient}
                    onToggle={handleToggle}
                    selected={selectedIds.has(ingredient.id)}
                  />
                  {mapped && (
                    <Button
                      className="justify-self-end text-xs"
                      onClick={() => {
                        setDemoMappings((current) => {
                          const next = { ...current }
                          delete next[ingredient.id]
                          return next
                        })
                        setSelectedIds((current) => {
                          const next = new Set(current)
                          next.delete(ingredient.id)
                          return next
                        })
                      }}
                      size="sm"
                      variant="ghost"
                    >
                      Clear mapping
                    </Button>
                  )}
                </div>
              )
            })}
          </div>

          <Button
            className="mt-4 h-[47px] w-full rounded-control border border-dashed text-sm"
            onClick={openManualCatalogue}
            variant="secondary"
          >
            <Plus aria-hidden="true" size={17} />
            Add Ingredient Manually
          </Button>

          {showCatalogue && (
            <section
              aria-labelledby="catalogue-panel-title"
              className="mt-4 rounded-card border border-forest-green/15 p-3"
            >
              <div className="flex items-start justify-between gap-2">
                <div>
                  <h3 className="text-sm font-bold" id="catalogue-panel-title">
                    {mappingTarget
                      ? `Map ${recognized.find((item) => item.id === mappingTarget)?.name ?? 'detection'} to catalogue`
                      : 'Find an ingredient in the Laravel catalogue'}
                  </h3>
                  <p className="mt-1 text-xs text-muted">
                    Results use real numeric catalogue IDs.
                  </p>
                </div>
                <Button
                  aria-label="Close catalogue panel"
                  className="h-8 w-8 shrink-0 rounded-full p-0"
                  onClick={closeCatalogue}
                  size="sm"
                  variant="ghost"
                >
                  <X aria-hidden="true" size={16} />
                </Button>
              </div>

              <div className="mt-3 grid gap-3">
                <Input
                  label="Search ingredient catalogue"
                  onChange={(event) => {
                    setSearch(event.target.value)
                    setPage(1)
                  }}
                  placeholder="Search by name, key or description"
                  value={search}
                />

                <div className="grid gap-1">
                  <label
                    className="text-sm font-semibold text-ink"
                    htmlFor={categorySelectId}
                  >
                    Category
                  </label>
                  {categoriesQuery.isPending ? (
                    <LoadingIndicator label="Loading categories..." />
                  ) : categoriesQuery.isError ? (
                    <div className="flex items-center gap-2 text-xs text-chilli-red">
                      <span>Categories could not be loaded.</span>
                      <Button
                        onClick={() => void categoriesQuery.refetch()}
                        size="sm"
                        variant="secondary"
                      >
                        Retry
                      </Button>
                    </div>
                  ) : (
                    <select
                      className="min-h-11 rounded-xl border border-forest-green/15 bg-warm-cream/60 px-3 text-sm text-ink"
                      id={categorySelectId}
                      onChange={(event) => {
                        setCategoryId(event.target.value)
                        setPage(1)
                      }}
                      value={categoryId}
                    >
                      <option value="">All categories</option>
                      {(categoriesQuery.data?.data ?? []).flatMap((category) => {
                        const id = parseApiId(category.id)
                        return id === null
                          ? []
                          : [
                              <option key={category.id} value={category.id}>
                                {category.name}
                              </option>,
                            ]
                      })}
                    </select>
                  )}
                </div>

                {mappingTarget && (
                  <div className="rounded-xl border border-spice-orange/30 bg-warm-cream/50 p-3">
                    <Input
                      error={
                        lookupValue.trim() && lookupId === null
                          ? 'Enter a positive numeric catalogue ID'
                          : undefined
                      }
                      hint="Optional: enter a numeric Laravel ingredient ID to map by ID."
                      label="Catalogue ingredient ID"
                      onChange={(event) => setLookupValue(event.target.value)}
                      value={lookupValue}
                    />
                    {lookupId !== null && lookupQuery.isPending && (
                      <div className="mt-2">
                        <LoadingIndicator label="Looking up ingredient..." />
                      </div>
                    )}
                    {lookupId !== null && lookupQuery.isError && (
                      <p className="mt-2 text-xs text-chilli-red" role="alert">
                        No catalogue ingredient was found for ID {lookupId}.
                      </p>
                    )}
                    {lookupSelection && (
                      <div className="mt-2 flex items-center justify-between gap-2">
                        <p className="text-xs text-ink">
                          {lookupSelection.name} (#{lookupSelection.id})
                        </p>
                        <Button
                          onClick={() => applyMapping(lookupSelection)}
                          size="sm"
                        >
                          Use this ingredient
                        </Button>
                      </div>
                    )}
                  </div>
                )}
              </div>

              <div className="mt-4 grid gap-2">
                {catalogueQuery.isPending ? (
                  <LoadingIndicator label="Loading catalogue ingredients..." />
                ) : catalogueQuery.isError ? (
                  <div className="rounded-xl border border-chilli-red/20 p-3 text-center">
                    <p className="text-sm font-bold">Catalogue unavailable.</p>
                    <p className="mt-1 text-xs text-muted">
                      {catalogueQuery.error.message}
                    </p>
                    <Button
                      className="mt-2"
                      onClick={() => void catalogueQuery.refetch()}
                      size="sm"
                    >
                      Try again
                    </Button>
                  </div>
                ) : (catalogueQuery.data?.data.length ?? 0) === 0 ? (
                  <p className="rounded-xl border border-dashed border-forest-green/20 p-4 text-center text-sm text-muted">
                    No catalogue ingredients match this search.
                  </p>
                ) : (
                  catalogueQuery.data?.data.map((ingredient) => {
                    const selection = toCatalogueSelection(ingredient)
                    if (!selection) {
                      return null
                    }
                    return (
                      <IngredientRow
                        ingredient={selection}
                        key={selection.id}
                        sourceLabel={`Laravel catalogue ingredient #${selection.id}`}
                        onRemove={
                          !mappingTarget &&
                          selectedIds.has(selection.id)
                            ? () => handleRemoveCatalogue(selection.id)
                            : undefined
                        }
                        onToggle={(_, selected) => {
                          if (!selected) {
                            return
                          }
                          if (mappingTarget) {
                            applyMapping(selection)
                            return
                          }
                          handleCatalogueToggle(selection, selected)
                        }}
                        selected={selectedIds.has(selection.id)}
                      />
                    )
                  })
                )}
              </div>

              {(catalogueQuery.data?.pagination.last_page ?? 1) > 1 && (
                <div className="mt-3 flex items-center justify-between gap-2">
                  <Button
                    disabled={page <= 1}
                    onClick={() => setPage((current) => Math.max(1, current - 1))}
                    size="sm"
                    variant="secondary"
                  >
                    Previous
                  </Button>
                  <span className="text-xs text-muted">
                    Page {catalogueQuery.data?.pagination.current_page ?? page} of{' '}
                    {catalogueQuery.data?.pagination.last_page ?? 1}
                  </span>
                  <Button
                    disabled={
                      page >= (catalogueQuery.data?.pagination.last_page ?? 1)
                    }
                    onClick={() =>
                      setPage((current) =>
                        Math.min(
                          catalogueQuery.data?.pagination.last_page ?? current,
                          current + 1,
                        ),
                      )
                    }
                    size="sm"
                    variant="secondary"
                  >
                    Next
                  </Button>
                </div>
              )}
            </section>
          )}
        </div>

        <section aria-labelledby="final-selection-title" className="mt-7">
          <h2 className="text-base font-bold" id="final-selection-title">
            Final selection ({finalSelection.length})
          </h2>
          {unmappedSelectedCount > 0 && (
            <p className="mt-2 text-xs font-semibold text-spice-orange" role="status">
              {unmappedSelectedCount} demo detection
              {unmappedSelectedCount === 1 ? '' : 's'} cannot be continued until
              mapped to a catalogue ingredient.
            </p>
          )}
          {finalSelection.length === 0 ? (
            <p className="mt-2 text-sm text-muted">No catalogue ingredients selected.</p>
          ) : (
            <ul className="mt-3 flex flex-wrap gap-2">
              {finalSelection.map((ingredient) => (
                <li
                  className="rounded-full bg-fresh-green/10 px-3 py-1 text-xs font-semibold text-ink"
                  key={ingredient.id}
                >
                  {ingredient.name}
                </li>
              ))}
            </ul>
          )}
        </section>

        <Button
          className="mt-7 h-[46px] w-full rounded-none text-sm"
          onClick={handleConfirm}
          size="lg"
        >
          Confirm &amp; Continue
        </Button>
      </section>
    </MobileScreen>
  )
}
