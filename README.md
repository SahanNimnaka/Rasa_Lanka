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
