<?php

namespace App\Http\Controllers;

use App\Search\SearchResult;
use App\Search\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ShellSearch (SHR-09). Results come only from providers that filter by the user's grants; the response
 * carries no totals, so the count never reveals records outside the user's scope.
 */
class SearchController extends Controller
{
    public function index(Request $request, SearchService $search): Response
    {
        $term = $this->term($request);

        return Inertia::render('search/index', [
            'q' => $term,
            'groups' => $this->payload($search->search($request->user(), $term, 20)),
        ]);
    }

    public function suggest(Request $request, SearchService $search): JsonResponse
    {
        return response()->json([
            'groups' => $this->payload($search->search($request->user(), $this->term($request), 5)),
        ]);
    }

    private function term(Request $request): string
    {
        return mb_substr(trim((string) $request->query('q', '')), 0, 100);
    }

    /**
     * @param  list<array{key: string, results: list<SearchResult>}>  $groups
     * @return list<array{key: string, results: list<array{type: string, title: string, url: string, subtitle: string|null}>}>
     */
    private function payload(array $groups): array
    {
        return array_map(fn (array $g) => [
            'key' => $g['key'],
            'results' => array_map(fn ($r) => $r->toArray(), $g['results']),
        ], $groups);
    }
}
