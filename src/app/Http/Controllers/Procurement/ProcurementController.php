<?php

namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Procurement screens (domain 06: suppliers and purchase orders). Every action authorizes through the central Gate,
 * in the Action or the Form Request, on the record.
 */
abstract class ProcurementController extends Controller
{
    protected const PER_PAGE = 15;

    protected function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    protected function toast(string $message): void
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
    }

    protected function like(string $term): string
    {
        return '%'.addcslashes(trim($term), '%_\\').'%';
    }
}
