<?php

namespace App\Http\Controllers\Rules;

use App\Actions\Rules\RulesAccess;
use App\Actions\Rules\SaveDocumentClause;
use App\Agreements\DocumentClauses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rules\DocumentClauseRequest;
use App\Models\Agreements\DocumentClause;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › contract and quote clauses (ADR-0037): the library with versions, the closed variables and a preview.
 */
class DocumentClauseController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        RulesAccess::authorizeView($user);

        return Inertia::render('rules/clauses', [
            'clauses' => array_values(DocumentClause::query()->orderByRaw('archived_at is not null')->orderBy('position')->orderBy('id')->get()
                ->map(fn (DocumentClause $c) => [
                    'id' => $c->id, 'key' => $c->key, 'title' => $c->title->toArray(), 'body' => $c->body->toArray(),
                    'applies_to' => $c->applies_to, 'included_by_default' => $c->included_by_default, 'position' => $c->position,
                    'version' => $c->version, 'archived' => $c->archived_at !== null, 'lock_version' => $c->lock_version,
                ])->all()),
            'variables' => DocumentClauses::VARIABLES,
            'samples' => ['ar' => DocumentClauses::samples('ar'), 'en' => DocumentClauses::samples('en')],
            'canEdit' => RulesAccess::canEdit($user),
        ]);
    }

    public function store(DocumentClauseRequest $request, SaveDocumentClause $save): RedirectResponse
    {
        $save->handle($request->user(), null, $request->safe()->except('lock_version'), null);

        return $this->saved();
    }

    public function update(DocumentClauseRequest $request, DocumentClause $clause, SaveDocumentClause $save): RedirectResponse
    {
        $save->handle($request->user(), $clause, $request->safe()->except('lock_version'), $request->integer('lock_version') ?: null);

        return $this->saved();
    }

    public function archive(Request $request, DocumentClause $clause, SaveDocumentClause $save): RedirectResponse
    {
        $save->archive($request->user(), $clause);

        return $this->saved();
    }

    private function saved(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => __('rules.saved')]);

        return to_route('rules.clauses');
    }
}
