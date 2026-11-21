# Phase 16.1 Testing Audit — Existing RasaLanka Application

**Audit date:** 21–22 November 2026

**Repository state at start:** clean tracked tree; unrelated untracked Phase 13.2/13.3 research files were left untouched.

**Application under test:** existing backend (`backend/`) and frontend (`frontend/`) as checked out at the start of Phase 16.1. No application code was changed during this audit.

## 1. Purpose and scope

This audit documents what was actually tested against the current RasaLanka application, what was only inspected in code, and what remains untested. It covers:

- **Backend:** authentication, recipe APIs, ingredient APIs, search/filtering, recommendations, saved recipes, cooking history, validation, authorization and error handling.
- **Frontend:** registration/login, Home, search/filtering, recipe details, ingredient selection, quantity management, serving-size changes, recommendations, saved recipes, cooking history, cooking instructions, camera/image upload, loading/empty/error states and responsive layout.
- **Integration:** endpoint paths, request/response structures, auth cookie/CSRF handling, error mapping, React Query invalidation, database persistence and user-specific isolation.
- **AI readiness:** clear separation of demo recognition, real model inference, image upload, backend integration and the correction workflow.
- **Responsive behaviour:** mobile (390 px), tablet (768 px) and desktop (1280 px) simulated widths.

The report deliberately distinguishes four evidence classes:

| Evidence class | Meaning |
|---|---|
| **Executed** | A command, API request or browser interaction was run and the result observed. |
| **Manual observation** | A browser screen was exercised by a human-controlled tool session; no automated assertion framework. |
| **Code inspection** | Source/config was read; behaviour was inferred but not executed. |
| **Untested** | Not exercised; listed explicitly rather than assumed working. |

Status labels used below:

| Status | Meaning |
|---|---|
| **PASS** | Executed and behaved as expected. |
| **PARTIAL** | Some paths work; gaps, limitations or inconsistencies remain. |
| **FAIL** | Executed and produced incorrect behaviour. |
| **ENV-BLOCKED** | Could not be verified in this local environment because of missing schema/config, without modifying the database. |
| **CODE-ONLY** | Reviewed in source only. |
| **NOT TESTED** | Not exercised. |

---

## 2. Executive summary

### 2.1 Overall result

| Area | Result |
|---|---|
| Backend automated suite | **PASS** — 77 passed, 4 skipped, 808 assertions. |
| Frontend lint | **PASS** — `npm run lint` exited 0. |
| Frontend build | **PASS with warning** — TypeScript/Vite build succeeded; main JS chunk is 817.00 kB / 251.40 kB gzip. |
| Frontend automated suite | **PARTIAL** — 204/205 tests passed in the full run; the cooking-flow test timed out at the default 5 s limit but passed in isolation with a 20 s timeout. |
| Public API probes | **PASS** — health, categories, ingredients, recipes and detail endpoints returned 200 with the expected envelope and CORS headers. |
| Auth API probes | **PASS after CSRF token refresh** — register 201, login 200, `/auth/me` 200, logout 200, `/auth/me` after logout 401. Stale XSRF tokens correctly produced 419. |
| Saved recipes / history (live local PostgreSQL) | **FAIL / ENV-BLOCKED** — endpoints returned 500 because `saved_recipes` and `recipe_views` tables are absent in the local `rasalanka` database. The same endpoints pass in the SQLite-backed automated suite. |
| Browser feature checks | **PARTIAL** — Home, login, recipe detail, cooking flow, camera/upload, demo recognition, catalog correction, quantity, servings and recommendations were observed. Saved/history screens showed raw SQL error text. |
| Responsive checks | **PASS for layout structure** — no document-level horizontal overflow at 390/768/1280 px in the checked screens; navigation and grids changed as intended on fresh loads. Simulated resize/touch behaviour remains a limitation. |
| AI readiness | **NOT AI-IMPLEMENTED** — camera and file upload are real, but recognition is three fixed demo rows with no image sent to a backend/model. Recommendations are local deterministic overlap, explicitly labelled as not AI-generated. |

### 2.2 Most important findings

1. **High — Saved recipes and cooking history are unusable in the running local environment.** The API returns 500 because required tables are missing from local PostgreSQL. This was not silently fixed or migrated; the automated SQLite suite passes, so this is most likely an environment/migration application gap, but the runtime failure is real.
2. **High — Backend 500 details are rendered verbatim in the frontend.** Saved/history screens displayed SQLSTATE text, table names, SQL fragments, connection host, port and database name. Production exception masking reduces this, but the UI should never render raw API error messages for 5xx responses.
3. **High/Medium — Unauthenticated API requests without SPA headers return 500 `Route [login] not defined.` instead of 401.** Normal frontend requests send `X-Requested-With`/JSON headers and are unaffected, but the API error contract is not robust for plain clients.
4. **Medium — Unsupported methods return 500 instead of 405.** `PATCH /api/v1/recipes/1` returned `INTERNAL_SERVER_ERROR` with the Symfony method-not-supported message.
5. **Medium — AI claims in the PWA manifest contradict the implementation.** The manifest says “AI-powered … recognition and recipe recommendations”; recognition is demo-only and recommendations are explicitly local/non-AI.
6. **Medium — Several user-state behaviours are local-only or partially wired:** settings “Clear history” does not clear backend history, saved-recipe heart state is not seeded from backend saved recipes, direct `/cooking/:id` does not record a view, and search filters are only partly server-side.
7. **Medium — Frontend test reliability issue:** the cooking-flow integration test fails only because of the default 5 s timeout; it passes with 20 s. This is a test-suite stability problem, not evidence that the flow is broken.

---

## 3. Environment and safe test data

### 3.1 Local services

| Service | Observed state |
|---|---|
| Backend | `http://127.0.0.1:8000/api/v1/health` returned 200. |
| Frontend | `http://127.0.0.1:5173/` and `http://localhost:5173/` returned 200. |
| Frontend API base URL | `frontend/.env` sets `VITE_API_URL=http://localhost:8000`. |
| Backend DB | `backend/.env`: `DB_CONNECTION=pgsql`, `DB_DATABASE=rasalanka`, `SESSION_DRIVER=database`. |
| Backend debug | `APP_ENV=local`, `APP_DEBUG=true`. This explains why raw exception text is present in local API responses. |
| CORS | `backend/config/cors.php` defaults to `http://localhost:5173`, `supports_credentials => true`, and exposes `X-XSRF-TOKEN`. |
| Sanctum | `backend/config/sanctum.php` configures stateful hosts including `localhost`, `127.0.0.1`, `localhost:5173` and `127.0.0.1:5173`; guard is `['web']`. |

### 3.2 Test account

A dedicated safe account was created through the API for browser/manual checks:

- ID: `2`
- Name: `Phase 16.1 Audit`
- Email: `phase161.audit.1795284925824@example.test`

No real user data was deleted. No production database was contacted. No migration, seeder or destructive reset was run against the local PostgreSQL database.

### 3.3 Known environment limitation

Live saved-recipe and history requests against local PostgreSQL failed with:

- `SQLSTATE[42P01] ... relation "saved_recipes" does not exist`
- `SQLSTATE[42P01] ... relation "recipe_views" does not exist`

Because the task explicitly prohibited silent fixes and database modification, no migration was applied. The automated backend suite covers these endpoints on a test database and passed; the failure is therefore classified as **ENV-BLOCKED for live verification** plus a **real UI error-handling failure** because the frontend displayed the raw SQL text.

---

## 4. Automated verification

### 4.1 Commands and results

| Command | Result | Evidence |
|---|---|---|
| `npm test` (frontend) | 39 test files: 1 failed, 38 passed. 205 tests: 1 failed, 204 passed. Failure: `src/App.cookingFlow.test.tsx` exceeded the 5 s default timeout. | `phase161_frontend_tests.log` |
| `npx vitest run src/App.cookingFlow.test.tsx --testTimeout=20000` | 1 file passed, 1 test passed, exit code 0. | `phase161_cookingflow_retry.log` |
| `npm run lint` (frontend) | Exit code 0, no ESLint findings. | `phase161_frontend_lint.log` |
| `npm run build` (frontend) | TypeScript + Vite build succeeded in 1.77 s; PWA generated 22 precache entries. Warning: `dist/assets/index-6-z8egj7.js` is 817.00 kB / 251.40 kB gzip, above 500 kB. | `phase161_frontend_build.log` |
| Backend `artisan test` with PHP 8.2.12 | 77 passed, 4 skipped, 808 assertions, 61.10 s. Skips are four `PostgreSqlRecipeImportTest` methods requiring a disposable database named `rasalanka_test_*` via `RASALANKA_TEST_PG_DATABASE`. | `phase161_backend_tests.log` |

### 4.2 Interpretation

- The frontend suite is broadly healthy but has a **timeout-sensitive integration test**. Raising the timeout made it pass; this should be fixed by stabilizing the test or configuring an appropriate timeout, not by ignoring the failure in the default run.
- Backend coverage is strong for the implemented API surface. The skipped PostgreSQL import tests remain an untested area.
- Lint and build are clean apart from the bundle-size warning.

---

## 5. Backend audit

### 5.1 Route inventory

`backend/routes/api.php` exposes the following authoritative route groups under `/api/v1`:

| Area | Routes |
|---|---|
| Health | `GET /health` |
| Categories | `GET /recipe-categories`, `GET /ingredient-categories` |
| Ingredients | `GET /ingredients`, `GET /ingredients/{ingredient}` |
| Recipes | `GET /recipes`, `GET /recipes/{recipe}` |
| Auth | `POST /auth/register`, `POST /auth/login`, `GET /auth/me`, `POST /auth/logout` |
| Saved recipes (`auth:sanctum`) | `GET /saved-recipes`, `POST /saved-recipes`, `DELETE /saved-recipes/{recipe}` |
| Recipe history (`auth:sanctum`) | `GET /recipe-history`, `POST /recipe-history`, `DELETE /recipe-history` |
| Fallback | API 404 envelope |

There is **no** recommendation route, **no** image upload/recognition route, and **no** recipe create/update/delete route. The frontend matches this inventory; it does not call endpoints that do not exist.

### 5.2 Backend feature results

| Feature | Status | Evidence and notes |
|---|---|---|
| Authentication | **PASS** | API probes: CSRF bootstrap 204, register 201, login 200, `/auth/me` 200, logout 200, `/auth/me` after logout 401. Frontend auth tests cover validation, duplicate email, protected routes, session restore, logout and 401 clearing. |
| Auth transport | **PARTIAL / CODE-ONLY** | Cookie-session Sanctum only. `backend/config/sanctum.php` uses `guard => ['web']`; no `personal_access_tokens` migration and no `HasApiTokens` usage were found. Suitable for the current first-party SPA, but there is no bearer-token API support for other clients. |
| Recipe APIs | **PASS** | Live `GET /recipes` and `GET /recipes/1` returned 200 with `success`, `data`, `links` and `meta.pagination` where applicable. Detail includes ingredients, instructions, sources and data-quality relationships. Automated relationship/API tests passed. |
| Ingredient APIs | **PASS** | Live list, search and detail returned 200; detail included categories with provenance and related recipe ingredients. Frontend service/hook tests cover `include[]` serialization and mapping. |
| Categories | **PASS** | Recipe and ingredient category endpoints returned 200; category counts were present. |
| Search/filtering | **PARTIAL** | API supports `search`, `recipe_category_id`, `ingredient_id`, `page`, `per_page` and `sort`; invalid `sort` returned 422 `VALIDATION_FAILED`. However, frontend cooking-time filtering is local to the loaded page and only the first selected ingredient is sent to the API. |
| Recommendations | **PASS for absence / NOT TESTED as AI** | No backend recommendation route exists. Frontend computes local overlap from Laravel recipe data. There is nothing backend-side to test as AI. |
| Saved recipes | **ENV-BLOCKED + FAIL** | Controller is user-scoped (`$request->user()->savedRecipes()` and `firstOrCreate` with `user_id`). Automated tests pass. Live PostgreSQL requests returned 500 because `saved_recipes` is absent. |
| Cooking history | **ENV-BLOCKED + FAIL** | Controller is user-scoped through `$request->user()->recipeViews()`. Automated tests pass. Live PostgreSQL requests returned 500 because `recipe_views` is absent. |
| Validation | **PASS** | Invalid `sort` → 422 with field details. Invalid `recipe_id` on save → 422 with `details.recipe_id`. Frontend Zod schemas and backend FormRequests were inspected; auth/validation tests passed. |
| Authorization | **PARTIAL** | Protected routes use `auth:sanctum`. Saved/history controllers scope all reads/writes to the authenticated user. No admin/role model was found. Live two-user isolation was not exercised; automated tests and code inspection are the evidence. |
| Error handling | **FAIL in several cases** | 401/403/404/422/419/429 have explicit renderers in `bootstrap/app.php`. But: plain unauth requests can return 500 `Route [login] not defined.`; `HttpException` is only specially handled for 419, so `PATCH` returns 500 instead of 405; generic `Throwable` exposes the real message when `APP_DEBUG=true`. |

### 5.3 Notable backend code evidence

- `bootstrap/app.php:96-106` only maps `HttpException` to a structured response when status is 419. Other HTTP exceptions fall through to `Throwable` and become 500.
- `bootstrap/app.php:140-153` renders `$exception->getMessage()` outside production. This is useful locally but must not be trusted by the UI.
- `SavedRecipeController.php:15-24` and `RecipeHistoryController.php:13-22` prove user-scoped reads.
- `SavedRecipeController.php:43-52` proves scoped delete with an explicit 404 when nothing was deleted.
- `RecipeHistoryController.php:37-43` proves scoped clear.

---

## 6. Frontend audit

The read-only frontend inventory found 39 test files, 40 `describe` blocks and 205 rendered test cases. The full test run results are in section 4.

### 6.1 Frontend feature results

| Feature | Status | Evidence and notes |
|---|---|---|
| Registration/login | **PASS** | Automated tests cover field validation, duplicate-email mapping, generic auth errors, loading/disabled submit, password toggles, protected-route intent preservation and session restore. Manual browser login with the safe account navigated to `/home`. |
| Splash/onboarding | **PASS** | Tests cover timed splash → onboarding, three steps, indicators, browser back, Skip and Get Started. |
| Home | **PARTIAL** | Manual browser: recipes loaded and “Welcome, Sahan” was shown. Automated tests cover API recipes, empty state, error state and navigation. Finding: greeting is hard-coded rather than using the signed-in user. |
| Search/filtering | **PARTIAL** | Manual browser: category chips, cooking-time note, ingredient selection, pagination and empty state were visible. API requests for `search=potato` and category filters were observed. Finding: filters persisted from the earlier cooking-flow session and initially showed “No recipes match these filters”; Reset filters restored six results. Cooking time and extra ingredients are local-only. |
| Recipe details | **PASS** | Manual recipe `/recipes/15` rendered “Ala Hodi”, ingredients, instructions/tabs, data-quality text and explicit image/video placeholders. Automated tests cover DTO mapping, quantity provenance, repeated ingredients, sources and error/empty states. |
| Ingredient selection | **PARTIAL** | Camera/upload navigation and recognition review were exercised. Demo rows were unmapped by default; a real catalogue ingredient could be searched, selected and carried forward. Confirmation does not block empty/unmapped selections. |
| Quantity management | **PASS** | Manual: invalid `abc` was rejected; `2` was accepted and advanced. Automated tests cover empty, negative, zero, nonnumeric, free-text units and metadata preservation. |
| Serving-size changes | **PASS with finding** | Manual: increment from 2 to 3 persisted into recommendations text “Recipes for 3 people”. Automated tests cover bounds and quick selection. Finding: `6+` sets exactly 6. |
| Recommendations | **PARTIAL** | Manual: loaded Laravel recipe data, showed “Recipes for 3 people”, local overlap counts and the explicit “not AI-generated” disclosure. No backend route exists. |
| Saved recipes | **FAIL / ENV-BLOCKED** | Browser screen showed loading → error with raw SQL text and a working-looking retry control. Live API 500 due missing table. Automated tests cover list, category chips, unsave and retry on mocked data. |
| Cooking history | **FAIL / ENV-BLOCKED** | Browser screen showed raw SQL error and retry. Live API 500 due missing table. Automated tests cover list, clear, cancel, retry, pagination and loading. |
| Cooking instructions | **PASS** | Manual: `/cooking/15` showed step-by-step instructions and ingredients tabs. Automated tests cover step numbering, progress, completion, route changes, stale selection, local quantities and error states. |
| Camera | **PARTIAL** | Manual: `/ingredients/camera` rendered a video element and camera controls; capture was not exercised against a real device. Automated tests cover permission-denied, unsupported, track cleanup and torch gating. |
| Image upload | **PARTIAL** | Manual: JPG/PNG file input accepted `frontend/public/rasalanka-logo.png`, preview/continue navigated to recognition. Automated tests cover validation, preview/replace/remove and recent images. Real model inference did not occur. |
| Loading/empty/error states | **PARTIAL** | Automated tests cover explicit states on Home, Search, Details, Recommendations, Saved, History, Guidance, Instructions and catalogue correction. Manual live error state for Saved/History exposed raw SQL, which is an error-handling failure. |
| Profile/preferences/settings | **CODE-ONLY / PARTIAL** | Tests cover local demo name editing, preference persistence, theme and language copy. No backend endpoints exist for these features. |
| Notifications/forgot password | **CODE-ONLY** | Explicit placeholder screens; no backend integration. |

### 6.2 Frontend integration details

Executed and inspected integration points:

- `src/services/api.ts` sets `baseURL` to `${VITE_API_URL}/api/v1`, `withCredentials: true`, `withXSRFToken: true`, JSON headers and `X-Requested-With`.
- `bootstrapCsrf()` calls `/sanctum/csrf-cookie` before state-changing requests.
- The 401 interceptor invokes `AuthProvider.clearUser()` except on `/auth/me`, `/auth/login` and `/auth/register`.
- `toApiError()` normalizes Axios failures into `ApiError` with `status`, `code` and `details`.
- Services call the expected endpoints: `/recipes`, `/recipes/{id}`, `/ingredients`, `/ingredients/{id}`, category endpoints, `/saved-recipes`, `/saved-recipes/{id}`, `/recipe-history`.
- React Query defaults: 5 min `staleTime`, 30 min `gcTime`, no retry on 400/401/403/404, up to two retries for transient/5xx failures, no mutation retries.
- Query keys are normalized and namespaced. Save/unsave invalidates `savedRecipes`; record/clear invalidates `recipeHistory`. These invalidations are covered by automated tests but could not be validated end-to-end on live PostgreSQL.

---

## 7. AI readiness assessment

This section is intentionally explicit: the current application is **AI-ready in structure, not AI-enabled in behaviour**.

| Capability | Current state | Evidence |
|---|---|---|
| Camera capture | **Real browser capability** | `CameraScreen.tsx` uses `getUserMedia`, environment facing mode, torch only when supported, cleanup on unmount and gallery fallback. Browser rendered the camera screen; real hardware capture was not tested. |
| Image upload | **Real browser capability** | `UploadImageScreen.tsx` accepts JPG/PNG up to 5 MB, previews with `FileReader`, supports drag/drop and session recent images. Manual upload navigated to recognition. |
| Image sent to backend | **No** | No upload/recognition endpoint exists in `backend/routes/api.php`; frontend service layer has no recognition API client. |
| Real model inference | **No** | `src/services/recognitionService.ts:3-14` declares “Demonstration results only — no AI recognition model is connected yet” and returns fixed Red lentils 98, Coconut milk 95, Onion 92 with `source: 'demo'`. |
| Object detection / bounding boxes | **No** | No boxes, segmentation, localization or detection metadata were found. |
| Quantity inference | **No** | Quantities are entered manually. `quantityService.ts` provides defaults and validation; recipe detail quantities are source-yield divisions with explicit “validation pending / expert approval pending” language. |
| Correction workflow | **Partially real** | Demo detections can be mapped to real Laravel catalogue rows by search/category/pagination/numeric ID. Numeric IDs are enforced before entering the cooking flow. This is a useful integration seam for future model output. |
| Persistence | **Partial** | Selected catalogue ingredients, servings and flow state persist in `sessionStorage` under `rasalanka.cooking-flow.v1`. Uploaded images/recent previews are session-only. No recognition result is persisted server-side. |
| Recommendations | **Local deterministic, not AI** | Frontend loads up to 50 Laravel recipe summaries plus details and ranks by ingredient overlap. UI says ratings/images are absent and “These are not AI-generated recommendations.” |

### 7.1 Research-aligned readiness path

The prior research decision remains the correct next step:

1. Implement **multi-label image classification** first, returning candidate ingredient labels with confidence and a model/provenance identifier.
2. Keep object detection out of scope until reliable bounding boxes and a measured usability benefit exist.
3. Keep quantity/count/mass/volume entry **manual**; do not infer amounts from image labels.
4. Add a backend recognition endpoint with image validation, size/type limits, auth/rate-limit policy and a structured response that fits the existing catalogue correction UI.
5. Reuse the current correction workflow to map model labels to numeric Laravel ingredient IDs; never persist demo IDs.
6. Update the PWA manifest and UI copy only when real inference is connected. Until then, remove the “AI-powered” claim or qualify it accurately.

---

## 8. Responsive layout findings

### 8.1 Method and limitation

The browser tool does not expose a native viewport-resize API. Layout was therefore checked by freshly loading the app inside same-origin iframes at widths **390 px**, **768 px** and **1280 px** and reading computed layout metrics. Each width was loaded fresh so `matchMedia` initial state was correct. Measurements were taken on **Home**, **Search** and **Recipe Details**.

This is a useful CSS/layout simulation, not a substitute for real-device testing. Touch gestures, virtual keyboards, device pixel ratio, camera behaviour and actual browser window resize events were not verified. During dynamic iframe resizing, the desktop navigation state did not update in the simulation even though CSS media queries did; fresh loads at each width rendered the correct navigation. Because the code uses a `matchMedia('(min-width: 768px)')` change listener, this was treated as a simulation artefact rather than a confirmed app bug.

### 8.2 Observed results

| Screen | 390 px | 768 px | 1280 px |
|---|---|---|---|
| Home | Bottom nav visible; single-column cards/grids; no document overflow. | Desktop header/nav visible; two-column shortcut grid; no document overflow. | Desktop header/nav visible; main two-column layout (`~597px` + `~540px`); no document overflow. |
| Search | Single-column filter panel and results; no document overflow. | Filters full width; result grid two columns (`~337px` each); no document overflow. | Filter rail `360px` + results `777px`; result grid three columns (`~248px` each); no document overflow. |
| Recipe Details | Single-column detail grid; two-column metadata card; tabs in three columns; no document overflow. | Single-column main detail grid; metadata/tabs widen; desktop nav visible; no document overflow. | Two-column detail grid (`~625px` + `~512px`); desktop nav visible; no document overflow. |

`document.documentElement.scrollWidth` matched `documentElement.clientWidth` at every checked width (375/753/1265 content width due scrollbar), so there was no horizontal page overflow. A decorative `.logo-glow` span overflowed its own box by 6–8 px at tablet/desktop, but it did not create page-level overflow or a visible content failure.

### 8.3 Responsive conclusion

**PASS for structural responsive layout** on the three sampled screens. No mobile/tablet/desktop layout failure was observed in the simulation. Real-device verification remains outstanding.

---

## 9. Consolidated bug list by severity

### 9.1 High

| ID | Finding | Evidence | Impact |
|---|---|---|---|
| H1 | Saved recipes/history endpoints fail 500 in the running local environment because `saved_recipes` and `recipe_views` are missing from PostgreSQL. | API probes and browser screens; automated SQLite tests pass. | Core user features cannot be used locally. Likely migration/application gap, but the runtime result is a hard failure. |
| H2 | Frontend renders raw backend exception/SQL text in error states. | Browser `/saved` and `/history` displayed SQLSTATE, SQL fragments, host, port and DB name. | Information disclosure and poor UX; production masking is not a substitute for client-side generic 5xx copy. |
| H3 | Plain unauthenticated requests to protected APIs can return 500 `Route [login] not defined.` instead of 401. | `phase161_api_probe.log`; requests without `Accept`/`X-Requested-With`. | Broken API error contract for non-SPA clients; inconsistent auth semantics. |
| H4 | `PATCH /api/v1/recipes/1` returns 500 instead of 405. | `phase161_auth_followup.log`; `bootstrap/app.php:96-106` handles only 419 `HttpException`. | Incorrect HTTP semantics and misleading error codes. |

### 9.2 Medium

| ID | Finding | Evidence | Impact |
|---|---|---|---|
| M1 | Direct `/cooking/:recipeId` is not wrapped by `RecipeViewRoute`, so viewing a recipe via Start Cooking may not record history. | Code inspection of `App.tsx`; live check blocked by missing table. | Cooking history undercounts views. |
| M2 | Settings “Clear history” clears only local demo state; backend `/recipe-history` remains intact. | Code inspection of `SettingsScreen.tsx` and `HistoryScreen.tsx`. | User believes history was cleared when server data persists. |
| M3 | Saved-recipe heart/favorite state is local session state and is not seeded from backend saved recipes. | Code inspection of `CookingFlowContext`, `cookingFlowStore`, `useToggleSavedRecipe`. | A saved recipe can appear unsaved; first click can issue a duplicate save. Live check blocked by H1. |
| M4 | Recognition confirmation accepts empty or unmapped demo selections despite warning text and a `cannot be continued` message. | Code inspection of `IngredientRecognitionScreen.tsx` `handleConfirm()` and always-enabled Confirm button. | Users can advance into a blocked quantities screen or silently lose unmapped detections. |
| M5 | Search is only partly server-side. | UI text and code: cooking time filtered on loaded page; only first ingredient becomes `ingredient_id`. | Results/pagination can be misleading for extra filters. |
| M6 | Frontend default test run fails due a 5 s timeout in the cooking-flow test. | `phase161_frontend_tests.log`; isolated 20 s retry passed. | CI can report failure even though the flow works. |
| M7 | PWA manifest claims “AI-powered” recognition and recommendations. | `vite.config.ts:21-22`; recognition and recommendation sources contradict this. | Overstates implemented capability; trust/documentation issue. |
| M8 | Hard-coded “Welcome, Sahan” greeting appears after login as the audit test user. | Manual browser observation after login as `Phase 16.1 Audit`; `HomeScreen.tsx:89`. | Personalization bug. |
| M9 | Generic 5xx messages are not sanitized by the API error mapper. | H2 evidence; `toApiError()` preserves API message. | Enables H2 across other error surfaces. |

### 9.3 Low / known limitations

| ID | Finding | Evidence |
|---|---|---|
| L1 | Main JS chunk is 817.00 kB / 251.40 kB gzip, above Vite’s 500 kB warning threshold. | `phase161_frontend_build.log`. |
| L2 | Bearer-token/PAT authentication is not implemented; cookie-session Sanctum is the only supported transport. | Route/config/migration inspection. Acceptable for current SPA, limiting for other clients. |
| L3 | Profile, preferences, settings, language and notifications are local/placeholder features with no backend endpoints. | Frontend service/route inspection and tests. |
| L4 | Saved-recipe category chips are heuristic string matches (`curry`/`sambol`/`other`). | `SavedRecipesScreen.tsx` inspection. |
| L5 | `6+` serving option sets exactly 6 despite “6 or more” wording. | `ServingSizeScreen.tsx` inspection/tests. |
| L6 | Legacy demo modules remain in `src/services` and `src/pages/login`. | Frontend audit inventory. |
| L7 | Local `AppStateActions.recordRecipeView` is unused; actual view recording is done by `RecipeViewRoute`. | Frontend state inspection. |
| L8 | Four PostgreSQL import tests are skipped without a disposable `rasalanka_test_*` database. | `phase161_backend_tests.log`. |
| L9 | Documentation is stale about which endpoints/features are implemented. | Backend route inventory vs docs. |
| L10 | Recipe images, video and ratings are placeholders by design. | Recipe detail/guidance UI and mappers. |
| L11 | `127.0.0.1:5173` is blocked by the default CORS origin `http://localhost:5173`. | Browser observation; expected from config, but use `localhost` for development. |

---

## 10. Recommended testing and fixing order

### Priority 0 — unblock core user features

1. Verify/apply the missing schema for `saved_recipes` and `recipe_views` in the intended local/dev environment, using an approved disposable or development database. Do not touch production or real user data.
2. Re-run:
   - backend `artisan test`
   - authenticated API probes for list/store/delete/clear saved recipes and history
   - browser checks for save, unsave, view recording, clear and two-user isolation
3. Once live 500s are gone, re-check React Query invalidation end-to-end.

### Priority 1 — error handling and API contract

4. Map all non-2xx API responses to generic, user-safe UI messages. Never render `error.message` for 5xx.
5. Extend `bootstrap/app.php` so all `HttpException` statuses map to structured codes, especially 405.
6. Ensure unauthenticated API requests return 401 even without `Accept: application/json` or `X-Requested-With`.
7. Re-run the public API probe suite and add regression tests for 401, 405 and 500 masking.

### Priority 2 — correctness of user state

8. Fix direct cooking view recording or accept and document that only detail/guidance views count.
9. Make Settings “Clear history” call the backend clear endpoint as well as local state.
10. Seed favorite/saved state from `/saved-recipes` so the heart icon reflects server state before the first click.
11. Block confirm when no catalogue-mapped ingredients remain; explicitly surface dropped unmapped detections.
12. Decide whether search filters should be fully server-side; otherwise keep the current honesty but improve result-count wording and pagination expectations.

### Priority 3 — AI honesty and readiness

13. Remove or qualify the “AI-powered” PWA description until real inference exists.
14. Add a backend recognition endpoint contract first; keep demo mode behind an explicit flag for development.
15. Reuse the catalogue correction UI for model labels, preserving numeric-ID enforcement and manual quantity entry.
16. Add tests for model-response mapping, confidence provenance, unmapped labels, image validation and correction persistence.

### Priority 4 — quality and performance

17. Stabilize the cooking-flow test (appropriate timeout or reduced setup cost) so the default `npm test` run is green.
18. Review code splitting for the 817 kB main chunk.
19. Personalize the Home greeting from `AuthUser.name`.
20. Clean up legacy demo modules and stale docs once they are confirmed unused.

### Priority 5 — real-device verification

21. Test on actual mobile and tablet browsers, including camera permission, torch/switch controls, virtual keyboard, portrait/landscape and touch targets.
22. Run PostgreSQL import tests with a disposable `rasalanka_test_*` database.
23. Perform explicit two-user isolation tests for saved recipes and history once the tables exist.

---

## 11. Explicitly untested areas

The following were **not** executed and must not be read as verified:

- Real AI/model inference of any kind.
- Real camera capture on a physical device, torch, lens switching or permission prompts beyond mocked tests.
- Server-side image upload/storage.
- Live saved-recipe and history persistence/isolation on PostgreSQL.
- Two distinct users interacting with saved recipes/history simultaneously.
- Production-mode error rendering.
- Bearer-token/PAT authentication.
- Real mobile/tablet devices, OS browsers, accessibility screen readers and touch interactions.
- PostgreSQL import tests requiring `RASALANKA_TEST_PG_DATABASE`.
- End-to-end PWA install/offline behaviour beyond build generation.
- Any destructive operation against real user data.

---

## 12. Evidence appendix

Captured command/API evidence (local temporary artifacts, not committed):

| File | Contents |
|---|---|
| `C:\Users\USER\AppData\Local\Temp\phase161_frontend_tests.log` | Full frontend test run: 1 failed / 204 passed, timeout detail. |
| `C:\Users\USER\AppData\Local\Temp\phase161_cookingflow_retry.log` | Isolated cooking-flow retry with 20 s timeout: passed. |
| `C:\Users\USER\AppData\Local\Temp\phase161_frontend_lint.log` | `npm run lint` output. |
| `C:\Users\USER\AppData\Local\Temp\phase161_frontend_build.log` | Build success and 817 kB chunk warning. |
| `C:\Users\USER\AppData\Local\Temp\phase161_backend_tests.log` | Backend suite: 77 passed, 4 skipped, 808 assertions. |
| `C:\Users\USER\AppData\Local\Temp\phase161_api_probe.log` | Public API status/CORS/body probes, invalid sort, 404s and unauth 500 cases. |
| `C:\Users\USER\AppData\Local\Temp\phase161_auth_api.log` | Initial auth/saved/history probes, stale-token 419 cases. |
| `C:\Users\USER\AppData\Local\Temp\phase161_auth_followup.log` | Refreshed-token auth flow, invalid save 422, PATCH 500, logout and post-logout 401. |

Browser evidence was observed in the in-app Chromium session at `http://localhost:5173` with the safe test account. No browser screenshots or request bodies were committed with this report.
