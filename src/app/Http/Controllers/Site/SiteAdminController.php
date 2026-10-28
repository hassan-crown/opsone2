<?php

namespace App\Http\Controllers\Site;

use App\Access\Authorizer;
use App\Access\Permission;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Shared base of the site admin screens (SitePages, PageEditor, Theme*, domain). Every action is authorized by the
 * central Gate in its writer; the tab flags only decide what to show.
 */
abstract class SiteAdminController extends Controller
{
    public function __construct(protected readonly Authorizer $access) {}

    protected function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    /**
     * @return array{pages: bool, theme: bool, domain: bool}
     */
    protected function tabs(User $actor): array
    {
        return [
            'pages' => $this->access->can($actor, Permission::P55) || $this->access->can($actor, Permission::P56),
            'theme' => $this->access->can($actor, Permission::P100) || $this->access->can($actor, Permission::P101),
            'domain' => $this->access->can($actor, Permission::P101),
        ];
    }

    protected function toast(string $message): void
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
    }
}
