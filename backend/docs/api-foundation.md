# RasaLanka API Foundation

## Current scope

The API currently provides:

- API prefix: `/api/v1`
- Health endpoint: `GET /api/v1/health`
- Authentication endpoints: `register`, `login`, `me`, and `logout`
- JSON success/error conventions
- API-specific exception rendering
- Sanctum SPA cookie architecture
- Environment-driven CORS and stateful domains
- Public, read-only recipe and ingredient endpoints:
  - `GET /api/v1/recipe-categories`
  - `GET /api/v1/ingredient-categories`
  - `GET /api/v1/ingredients`
  - `GET /api/v1/ingredients/{id}`
  - `GET /api/v1/recipes`
  - `GET /api/v1/recipes/{id}`

See `recipe-api.md` for query parameters, resource shapes, and read-only guarantees.

Saved-recipe, history, recognition, and recommendation endpoints are **not implemented yet**.

## Local development URLs

| Service | URL |
|---|---|
| React SPA (Vite) | `http://localhost:5173` |
| Laravel API | `http://127.0.0.1:8000/api/v1` |
| API health check | `http://127.0.0.1:8000/api/v1/health` |
| Sanctum CSRF cookie | `http://127.0.0.1:8000/sanctum/csrf-cookie` |
| Laravel welcome page | `http://127.0.0.1:8000/` |

Start the backend with:

```bash
php artisan serve
```

Start the frontend from the repository root with:

```bash
cd frontend
npm run dev
```

## Authentication architecture

RasaLanka uses Laravel Sanctum in **SPA cookie mode**.

- Authentication is session-based and stateful.
- The browser receives Laravel's encrypted session cookie.
- The session cookie is `HttpOnly`; no bearer token is stored in `localStorage`.
- Before state-changing API requests, the SPA calls `GET /sanctum/csrf-cookie`.
- The SPA then sends the readable `XSRF-TOKEN` cookie value back as the `X-XSRF-TOKEN` header.
- Protected routes use `auth:sanctum`.

### Authentication endpoints

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| `POST` | `/api/v1/auth/register` | Public | Creates a user, hashes the password, and starts the session. |
| `POST` | `/api/v1/auth/login` | Public | Verifies credentials and starts the session. |
| `GET` | `/api/v1/auth/me` | Required | Returns the current user. |
| `POST` | `/api/v1/auth/logout` | Required | Logs out, invalidates the session, and rotates the CSRF token. |

Registration requires `name`, `email`, `password`, and `password_confirmation`. Login requires `email` and `password`. Registration is limited to 3 attempts per minute per IP; login is limited to 5 attempts per minute per IP and email pair. Invalid credentials return a generic `401 UNAUTHENTICATED` response. No bearer tokens or `localStorage` tokens are used.

## CORS configuration

CORS settings live in `config/cors.php` and are environment-driven.

Local defaults:

```dotenv
FRONTEND_URL=http://localhost:5173
CORS_ALLOWED_ORIGINS=http://localhost:5173
SANCTUM_STATEFUL_DOMAINS=localhost,127.0.0.1,localhost:5173,127.0.0.1:5173
```

Key CORS behavior:

- `supports_credentials` is enabled.
- `api/*` and `sanctum/csrf-cookie` are CORS-enabled paths.
- `XSRF-TOKEN` is exposed to the browser.
- Allowed methods: `GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `OPTIONS`.

For production, set the exact frontend origin and stateful host. Do not assume a production domain until it is confirmed.

Example for same-registrable-domain subdomains:

```dotenv
APP_URL=https://api.example.com
FRONTEND_URL=https://app.example.com
CORS_ALLOWED_ORIGINS=https://app.example.com
SANCTUM_STATEFUL_DOMAINS=app.example.com
SESSION_DOMAIN=.example.com
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
SESSION_HTTP_ONLY=true
```

If the SPA and API are on different registrable domains, `SESSION_SAME_SITE=none` and `SESSION_SECURE_COOKIE=true` are required, and browser third-party cookie restrictions must be considered.

The actual `.env` file is deployment-specific and must not be committed.

## Required environment variables

| Variable | Purpose |
|---|---|
| `APP_URL` | Canonical backend URL. |
| `FRONTEND_URL` | Documentation/helper value for the first-party SPA origin. |
| `CORS_ALLOWED_ORIGINS` | Comma-separated list of exact origins allowed to call the API. |
| `SANCTUM_STATEFUL_DOMAINS` | Comma-separated hosts that receive stateful Sanctum cookies. Use hosts without scheme; include ports for local Vite. |
| `SESSION_DRIVER` | Session storage driver. Existing default is `database`. |
| `SESSION_DOMAIN` | Cookie domain. Use `.example.com` for subdomains; `null` for localhost. |
| `SESSION_SECURE_COOKIE` | `false` locally, `true` for HTTPS production. |
| `SESSION_HTTP_ONLY` | Keep `true` so JavaScript cannot read the session cookie. |
| `SESSION_SAME_SITE` | `lax` for same-site deployments; `none` only for cross-site HTTPS deployments. |

## JSON response convention

Success:

```json
{
  "success": true,
  "data": {},
  "meta": {
    "api_version": "v1"
  }
}
```

Paginated responses add `links` and `meta.pagination`.

Error:

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The given data was invalid.",
    "details": {}
  },
  "meta": {
    "api_version": "v1"
  }
}
```

## Frontend communication plan

The frontend already has Axios installed. When API endpoints are implemented, a dedicated client should:

1. Use `baseURL` pointing to the API, e.g. `/api/v1` in same-origin production or `http://127.0.0.1:8000/api/v1` locally.
2. Set `withCredentials: true`.
3. Call `/sanctum/csrf-cookie` before authentication or other state-changing requests.
4. Send `X-XSRF-TOKEN` from the `XSRF-TOKEN` cookie.
5. Parse the shared `success`, `data`, `error`, and `meta` structure.

Until endpoints exist, frontend services continue to use the current local mock boundary.
