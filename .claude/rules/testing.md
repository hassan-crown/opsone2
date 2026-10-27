# Quality and tests

- PHP format: Laravel Pint. Static analysis: PHPStan/Larastan when compatible.
- Test framework standard: Pest for new projects; respect an existing PHPUnit suite.
- React: TypeScript checks, ESLint/Prettier (or kit-provided equivalents), frontend build.
- Write meaningful Feature tests for auth/roles, workflows, API contracts and bug regressions.
- Never change tests just to make a failing implementation pass. Diagnose failures.
- Do not claim passing checks unless run; report 'not executed' and reason accurately.
- Suggested checks (run from root):
  `./manage.sh artisan test`, `./manage.sh node-build`.
  Optional from `src/` via Docker/CI: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`,
  `pnpm exec tsc --noEmit` (only if the project actually defines these tools/scripts).
- CI should run formatting/static analysis/tests/build without production secrets.
