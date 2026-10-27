<?php

namespace App\Http\Controllers\Catalog;

use App\Files\AttachmentService;
use App\Http\Requests\Catalog\ImageRequest;
use App\Models\Catalog\CatalogMaterial;
use App\Models\Catalog\CatalogProduct;
use App\Models\Catalog\CatalogReadyProduct;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Catalog images go through the private attachments service (ADR-0006): upload needs P-11/P-12/P-13 on the row,
 * quota counts against the license, and every download is a signed link that re-checks access.
 */
final class ImageController extends CatalogController
{
    public function product(ImageRequest $request, CatalogProduct $product, AttachmentService $files): RedirectResponse
    {
        return $this->store($request, $product, $files);
    }

    public function material(ImageRequest $request, CatalogMaterial $material, AttachmentService $files): RedirectResponse
    {
        return $this->store($request, $material, $files);
    }

    public function ready(ImageRequest $request, CatalogReadyProduct $ready, AttachmentService $files): RedirectResponse
    {
        return $this->store($request, $ready, $files);
    }

    private function store(ImageRequest $request, CatalogProduct|CatalogMaterial|CatalogReadyProduct $owner, AttachmentService $files): RedirectResponse
    {
        $files->store($this->actor($request), $owner, $request->file('file'), 'catalog_image');
        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.image.uploaded')]);

        return back();
    }
}
