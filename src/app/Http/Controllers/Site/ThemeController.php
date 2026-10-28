<?php

namespace App\Http\Controllers\Site;

use App\Access\Permission;
use App\Actions\Site\ThemeWriter;
use App\Http\Requests\Site\ImageRequest;
use App\Http\Requests\Site\ThemeRequest;
use App\Models\Site\SiteTheme;
use App\Site\Theme\BrandPalette;
use App\Site\Theme\ThemeSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ThemeEdit / ThemeApply (THM-01..07, M-35): one private draft, applied by a separate permission.
 */
final class ThemeController extends SiteAdminController
{
    public function edit(Request $request): Response
    {
        $actor = $this->actor($request);
        abort_unless($this->tabs($actor)['theme'], 403);
        $theme = SiteTheme::current();
        $editing = ThemeSettings::editing($theme);
        $brand = $editing['brand_input'] ?? BrandPalette::DEFAULT_COLOR;
        $derived = BrandPalette::derive($brand);

        $url = fn (?string $uuid) => $uuid === null ? null : route('site.admin.media', $uuid);

        return Inertia::render('site/theme', [
            'state' => $theme->state(),
            'settings' => [
                'brand_color' => $editing['brand_input'], 'font' => $editing['font'], 'appearance' => $editing['appearance'],
                'logo' => $url($editing['logo']), 'logo_dark' => $url($editing['logo_dark']), 'favicon' => $url($editing['favicon']),
            ],
            'published' => ['brand_color' => ThemeSettings::published($theme)['brand_input']],
            'preview' => $this->previewPayload($derived),
            'presets' => BrandPalette::PRESETS,
            'defaultColor' => BrandPalette::DEFAULT_COLOR,
            'fonts' => array_keys(ThemeSettings::FONTS),
            'can' => [
                'edit' => $this->access->can($actor, Permission::P100),
                'apply' => $this->access->can($actor, Permission::P101),
            ],
            'tabs' => $this->tabs($actor),
        ]);
    }

    /** Live contrast guard result for a colour (no write). */
    public function preview(ThemeRequest $request): JsonResponse
    {
        $this->access->authorize($this->actor($request), Permission::P100);
        $color = (string) ($request->validated('brand_color') ?: BrandPalette::DEFAULT_COLOR);

        return response()->json($this->previewPayload(BrandPalette::derive($color)));
    }

    public function save(ThemeRequest $request, ThemeWriter $writer): RedirectResponse
    {
        $result = $writer->saveDraft($this->actor($request), $request->validated());
        $this->toast($result['message'] ?? __('theme.toast.draft_saved'));

        return back();
    }

    public function upload(ImageRequest $request, string $kind, ThemeWriter $writer): RedirectResponse
    {
        $writer->uploadAsset($this->actor($request), $kind, $request->file('file'));
        $this->toast(__('theme.toast.asset_saved'));

        return back();
    }

    public function removeAsset(Request $request, string $kind, ThemeWriter $writer): RedirectResponse
    {
        $writer->removeAsset($this->actor($request), $kind);

        return back();
    }

    public function restore(Request $request, ThemeWriter $writer): RedirectResponse
    {
        $writer->prepareRestore($this->actor($request));
        $this->toast(__('theme.toast.restore_prepared'));

        return back();
    }

    public function apply(Request $request, ThemeWriter $writer): RedirectResponse
    {
        $writer->apply($this->actor($request));
        $this->toast(__('theme.toast.applied'));

        return back();
    }

    public function discard(Request $request, ThemeWriter $writer): RedirectResponse
    {
        $writer->discard($this->actor($request));
        $this->toast(__('theme.toast.discarded'));

        return back();
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private function previewPayload(array $d): array
    {
        return [
            'status' => $d['status'], 'input' => $d['input'], 'brand' => $d['brand'], 'message' => $d['message'] === null ? null : __($d['message']),
            'palette' => $d['palette'], 'report' => $d['report'],
        ];
    }
}
