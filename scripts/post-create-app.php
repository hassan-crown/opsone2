<?php

/**
 * Apply the company conventions to a freshly created Laravel app.
 *
 * Runs inside the PHP container with the app as working directory:
 *   ./manage.sh run -T app php < scripts/post-create-app.php
 *
 * Idempotent: running it again changes nothing.
 */

$changes = [];

/** Insert $entries as quoted array items right after every line matching $openPattern. */
function addToArrays(string $source, string $openPattern, array $entries): string
{
    return preg_replace_callback($openPattern, function (array $m) use ($entries) {
        $indent = $m[1].'    ';
        $lines = '';
        foreach ($entries as $entry) {
            $lines .= $indent."'".$entry."',\n";
        }

        return $m[0].$lines;
    }, $source);
}

// --- vite.config.(ts|js) -----------------------------------------------------
foreach (['vite.config.ts', 'vite.config.js'] as $viteFile) {
    if (! is_file($viteFile)) {
        continue;
    }
    $vite = $original = file_get_contents($viteFile);

    // The node image has no PHP: Wayfinder types are generated in the app container by manage.sh.
    if (str_contains($vite, 'wayfinder(') && ! str_contains($vite, 'WAYFINDER_COMMAND')) {
        $command = fn (string $i) => "{$i}command:\n{$i}    process.env.WAYFINDER_COMMAND ??\n{$i}    'php artisan wayfinder:generate',\n";
        $vite = preg_replace_callback('/^(\s*)wayfinder\(\{\n/m', fn ($m) => $m[0].$command($m[1].'    '), $vite, 1, $count);
        if ($count === 0) {
            $vite = preg_replace_callback('/^(\s*)wayfinder\(\),?\n/m', fn ($m) => "{$m[1]}wayfinder({\n".$command($m[1].'    ')."{$m[1]}}),\n", $vite, 1);
        }
    }

    // Tool caches bind-mounted by Docker live in src/ and must not be watched, linted or formatted.
    if (! str_contains($vite, '.pnpm-store')) {
        $vite = addToArrays($vite, '/^(\s*)ignored: \[\n/m', ['**/.composer/**', '**/.pnpm-store/**']);
        $vite = addToArrays($vite, '/^(\s*)ignorePatterns: \[\n/m', ['.composer/**', '.pnpm-store/**']);
    }

    if ($vite !== $original) {
        file_put_contents($viteFile, $vite);
        $changes[] = "{$viteFile}: Wayfinder command + tool cache ignores";
    }
}

// --- .gitignore ------------------------------------------------------------------
if (is_file('.gitignore')) {
    $lines = preg_split('/\R/', rtrim(file_get_contents('.gitignore'), "\n"));
    $before = $lines;
    // src/CLAUDE.md is part of the company standard and must be committed.
    $lines = array_values(array_filter($lines, fn ($l) => trim($l) !== '/CLAUDE.md'));
    foreach (['/.composer', '/.pnpm-store'] as $entry) {
        if (! in_array($entry, $lines, true)) {
            $lines[] = $entry;
        }
    }
    if ($lines !== $before) {
        file_put_contents('.gitignore', implode("\n", $lines)."\n");
        $changes[] = '.gitignore: tool caches ignored, src/CLAUDE.md tracked';
    }
}

// --- routes: Route::redirect -> Route::get + redirect() (Wayfinder-compatible verbs) ----
foreach (glob('routes/*.php') ?: [] as $routeFile) {
    $routes = file_get_contents($routeFile);
    $updated = preg_replace(
        '/Route::redirect\(\s*(\'[^\']*\'|"[^"]*")\s*,\s*(\'[^\']*\'|"[^"]*")\s*\)/',
        'Route::get($1, fn () => redirect($2))',
        $routes,
    );
    if ($updated !== $routes) {
        file_put_contents($routeFile, $updated);
        $changes[] = "{$routeFile}: Route::redirect -> Route::get";
    }
}

echo $changes === []
    ? ">>> [post-create] Conventions already applied.\n"
    : ">>> [post-create] Applied:\n  - ".implode("\n  - ", $changes)."\n";
