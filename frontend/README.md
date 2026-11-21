# RasaLanka

RasaLanka is a mobile-first Progressive Web App for Sri Lankan ingredient
recognition and recipe recommendations. The frontend uses React, TypeScript,
Vite, Tailwind CSS, and the Spicy Green palette.

## Development

- `npm install` installs the project dependencies.
- `npm run dev` starts the Vite development server.
- `npm run build` type-checks and builds the production app.
- `npm run test` runs the Vitest suite once; `npm run test:watch` runs it in
  watch mode.
- `npm run lint` checks the TypeScript and React code with ESLint.
- `npm run format` formats project files with Prettier.
- `npm run generate:pwa-assets` regenerates platform icons from
  `public/favicon.svg`.

The PWA manifest and Workbox service worker are configured in `vite.config.ts`.
The generated manifest includes standard and maskable Android icons; the Apple
touch icon is linked in `index.html`.

## Authentication

The frontend uses Laravel Sanctum SPA cookie authentication. Copy
`.env.example` to `.env` and set the Laravel backend origin:

```dotenv
VITE_API_URL=http://127.0.0.1:8000
```

`VITE_API_URL` is the backend origin only; the API client appends `/api/v1` and
`/sanctum/csrf-cookie` itself. Axios runs with `withCredentials` and
`withXSRFToken`; state-changing requests call `/sanctum/csrf-cookie` first.
Authentication state lives only in React memory. No token is stored in
`localStorage` or `sessionStorage`.

Public screens remain accessible without signing in. Personal screens
(`/profile`, `/saved`, `/history`, `/preferences`, `/settings`) redirect to
`/login` and preserve the intended destination.

## Recipe detail data

`RecipeDetailsScreen` loads `GET /api/v1/recipes/{id}` through the shared
`useRecipe(id)` hook. It validates the numeric route ID and shows explicit
invalid, loading, error/retry, and not-found states; it never falls back to the
mock recipe catalogue. Source quantities, units, approval status, requirement
labels, preparation notes, estimated/derived flags, data-quality warnings,
method summaries, numbered instructions, and recipe sources are displayed as
provided. Locally entered quantities from the cooking flow are shown alongside
API values without mutating query data. Images, ratings, match percentages,
availability, and nutrition are not invented; the existing image and video
placeholders remain.

`CookingGuidanceScreen` and `CookingInstructionsScreen` also load the selected
recipe through `useRecipe(id)` and never fall back to the mock catalogue. The
guidance screen shows the API name, description, preparation/cooking/total
times, method summary, and numbered instruction count. The instructions screen
uses the API method summary separately and renders numbered `recipe_instructions`
in their original order, excluding step 0. Both screens validate the numeric
route ID and handle invalid, loading, error/retry, and not-found states. The
shared cooking flow preserves the selected recipe ID and a valid step index;
changing recipes resets progress, while refetching does not. Locally entered
quantities are still shown alongside API values without mutating query data.
Video placeholders remain placeholders, and no media URLs or steps are
invented. Saved Recipes, History, recognition, and quantity-entry workflows
remain mock/local. Because History still depends on the mock catalogue, opening
an API-backed recipe does not add a History entry yet.

## Theme

Tailwind CSS exposes `forest-green`, `fresh-green`, `spice-orange`,
`warm-cream`, and `chilli-red` colors using the RasaLanka brand palette.

## Source structure

- `src/components/ui` contains reusable interface primitives.
- `src/components` contains shared brand and composition components.
- `src/layouts` contains page-level layout foundations.
- `src/pages` contains route-level screens.
- `src/hooks`, `src/services`, `src/types`, and `src/utils` are reserved for
  shared application logic, API access, domain types, and helpers.
- `src/assets` contains frontend assets.
