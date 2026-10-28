<?php

namespace App\Http\Controllers\Catalog;

use App\Access\Permission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * `/catalog` opens the first catalog section the viewer may manage; without any, the Gate's 403.
 */
final class CatalogHomeController extends CatalogController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $tabs = $this->tabs($actor);

        return match (true) {
            $tabs['products'] => to_route('catalog.products.index'),
            $tabs['ready'] => to_route('catalog.ready.index'),
            $tabs['materials'] => to_route('catalog.materials.index'),
            default => $this->deny($actor),
        };
    }

    private function deny(mixed $actor): never
    {
        $this->access->authorize($actor, Permission::P11);
        abort(403);
    }
}
