<?php

namespace App\Http\Controllers\Catalog;

use App\Access\Authorizer;
use App\Access\Permission;
use App\Catalog\CatalogPresenter;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Catalog screens (domain 02). Every action is authorized server-side through the central Gate; the tab list is
 * only a convenience of what the viewer may open (ready goods also needs the license capability, which the Gate checks).
 */
abstract class CatalogController extends Controller
{
    protected const PER_PAGE = 15;

    public function __construct(protected readonly Authorizer $access, protected readonly CatalogPresenter $presenter) {}

    protected function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    /**
     * @return array{products: bool, ready: bool, materials: bool}
     */
    protected function tabs(User $actor): array
    {
        return [
            'products' => $this->access->can($actor, Permission::P11),
            'ready' => $this->access->can($actor, Permission::P12),
            'materials' => $this->access->can($actor, Permission::P13),
        ];
    }

    protected function toast(string $message): void
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
    }

    /**
     * Escapes LIKE wildcards in a user search term.
     */
    protected function like(string $term): string
    {
        return '%'.addcslashes(trim($term), '%_\\').'%';
    }
}
