# Frontend conventions

- Default: React 19, TypeScript, Inertia 3, Vite, Tailwind 4, shadcn/ui (official starter kit).
- For Inertia, React components live in `src/resources/js`; Laravel owns the routes, controllers and authorization.
- Avoid duplicate SPA router/auth systems in the Inertia mode.
- Prefer reusable and accessible components. Follow existing design tokens and styling standards.
- No `any` types by default. Preserve strictness, typed props and safe handling of API payloads.
- Use Laravel validation as source of truth, mirror basic UX validation client-side as needed.
- For API+SPA mode put React under `src/frontend/` only after an approved architecture plan;
  adapt Node build, Nginx/deployment, CORS/CSRF and session settings explicitly.
- Do not introduce Next.js, SSR infrastructure, Redux, UI kits, or state libraries without a clear need.
- Support responsive layouts and translation/i18n when project requirements call for them.
- Wayfinder output (`resources/js/{actions,routes,wayfinder}`) is generated in the `app` container by `manage.sh`;
  never edit it. For redirect routes use `Route::get($uri, fn () => redirect($to))` rather than `Route::redirect()`.
