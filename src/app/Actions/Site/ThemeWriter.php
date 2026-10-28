<?php

namespace App\Actions\Site;

use App\Access\Authorizer;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\StateMachine;
use App\Files\AttachmentService;
use App\Models\Attachment;
use App\Models\Site\SiteTheme;
use App\Models\User;
use App\Site\Theme\BrandPalette;
use App\Site\Theme\ThemeSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * M-35: one private draft over the live theme. Saving a draft needs P-100, applying or restoring needs P-101; the
 * draft never touches visitors, and a failed apply keeps the live setting and the draft (THM-05, THM-07).
 */
final class ThemeWriter
{
    public function __construct(
        private readonly Authorizer $access,
        private readonly TransitionRecorder $transitions,
        private readonly AttachmentService $files,
    ) {}

    /**
     * @param  array{brand_color?: string|null, font?: string, appearance?: string}  $input
     * @return array{status: string, message: string|null}
     */
    public function saveDraft(User $actor, array $input): array
    {
        $this->access->authorize($actor, Permission::P100);

        return DB::transaction(function () use ($actor, $input) {
            $theme = SiteTheme::query()->lockForUpdate()->findOrFail(SiteTheme::current()->id);
            $base = ThemeSettings::editing($theme);
            $before = $theme->state();
            $status = 'ok';
            $message = null;

            if (array_key_exists('brand_color', $input)) {
                if ($input['brand_color'] === null || $input['brand_color'] === '') {
                    $base = array_merge($base, ['brand_input' => null, 'brand' => null, 'palette' => null]);
                } else {
                    $derived = BrandPalette::derive((string) $input['brand_color']);
                    if ($derived['status'] === 'rejected') {
                        throw ValidationException::withMessages(['brand_color' => __($derived['message'])]);
                    }
                    $base = array_merge($base, [
                        'brand_input' => $derived['input'], 'brand' => $derived['brand'], 'palette' => $derived['palette'],
                        'algorithm' => BrandPalette::ALGORITHM,
                    ]);
                    $status = $derived['status'];
                    $message = $derived['message'] === null ? null : (string) __($derived['message']);
                }
            }
            if (isset($input['font'])) {
                $base['font'] = $input['font'];
            }
            if (isset($input['appearance'])) {
                $base['appearance'] = $input['appearance'];
            }

            $theme->forceFill(['draft' => $base, 'draft_saved_at' => now(), 'draft_saved_by' => $actor->id])->save();
            $this->transitions->record(StateMachine::M35, 'T02', $theme, $before, 'draft', $actor, TransitionRecorder::SOURCE_WEB);
            Audit::record(AuditAction::SiteThemeDraftSaved, $theme, $actor, after: ['brand' => $base['brand'], 'font' => $base['font'], 'appearance' => $base['appearance']]);

            return ['status' => $status, 'message' => $message];
        });
    }

    /**
     * Stores a logo / dark logo / favicon as a private attachment of the theme and points the draft at it (Q-50 a:
     * PNG or WebP only; the favicon must be square).
     */
    public function uploadAsset(User $actor, string $kind, UploadedFile $file): Attachment
    {
        $this->access->authorize($actor, Permission::P100);
        abort_unless(in_array($kind, ThemeSettings::ASSET_KINDS, true), 422);

        $attachment = $this->files->store($actor, SiteTheme::current(), $file, 'theme_'.$kind);

        DB::transaction(function () use ($actor, $kind, $attachment) {
            $theme = SiteTheme::query()->lockForUpdate()->findOrFail(SiteTheme::current()->id);
            $before = $theme->state();
            $draft = ThemeSettings::editing($theme);
            $draft[$kind] = $attachment->uuid;
            $theme->forceFill(['draft' => $draft, 'draft_saved_at' => now(), 'draft_saved_by' => $actor->id])->save();
            $this->transitions->record(StateMachine::M35, 'T02', $theme, $before, 'draft', $actor, TransitionRecorder::SOURCE_WEB, context: ['asset' => $kind]);
            Audit::record(AuditAction::SiteThemeDraftSaved, $theme, $actor, after: ['asset' => $kind, 'attachment' => $attachment->uuid]);
        });

        return $attachment;
    }

    public function removeAsset(User $actor, string $kind): void
    {
        $this->access->authorize($actor, Permission::P100);
        abort_unless(in_array($kind, ThemeSettings::ASSET_KINDS, true), 422);

        DB::transaction(function () use ($actor, $kind) {
            $theme = SiteTheme::query()->lockForUpdate()->findOrFail(SiteTheme::current()->id);
            $before = $theme->state();
            $draft = ThemeSettings::editing($theme);
            $draft[$kind] = null;
            $theme->forceFill(['draft' => $draft, 'draft_saved_at' => now(), 'draft_saved_by' => $actor->id])->save();
            $this->transitions->record(StateMachine::M35, 'T02', $theme, $before, 'draft', $actor, TransitionRecorder::SOURCE_WEB, context: ['asset_removed' => $kind]);
            Audit::record(AuditAction::SiteThemeDraftSaved, $theme, $actor, after: ['asset_removed' => $kind]);
        });
    }

    /**
     * M-35-T02 «restore»: the default Alpha values go into the draft; content and pages are never touched.
     */
    public function prepareRestore(User $actor): void
    {
        $this->access->authorize($actor, Permission::P100);

        DB::transaction(function () use ($actor) {
            $theme = SiteTheme::query()->lockForUpdate()->findOrFail(SiteTheme::current()->id);
            $before = $theme->state();
            $theme->forceFill(['draft' => ThemeSettings::defaults(), 'draft_saved_at' => now(), 'draft_saved_by' => $actor->id])->save();
            $this->transitions->record(StateMachine::M35, 'T02', $theme, $before, 'draft', $actor, TransitionRecorder::SOURCE_WEB, context: ['restore' => true]);
            Audit::record(AuditAction::SiteThemeRestored, $theme, $actor, after: ['restore' => 'prepared']);
        });
    }

    /**
     * M-35-T03: validates the draft again (colour, font, files) and swaps it in as one set.
     */
    public function apply(User $actor): SiteTheme
    {
        $this->access->authorize($actor, Permission::P101);

        return DB::transaction(function () use ($actor) {
            $theme = SiteTheme::query()->lockForUpdate()->findOrFail(SiteTheme::current()->id);
            if ($theme->draft === null) {
                throw new InvalidTransition(StateMachine::M35, 'T03', 'no_draft');
            }
            $draft = array_merge(ThemeSettings::defaults(), $theme->draft);
            $this->assertApplicable($draft, $theme);
            if ($draft['brand_input'] !== null) {
                // Re-derived so a draft saved under an older algorithm never goes live with stale roles.
                $derived = BrandPalette::derive((string) $draft['brand_input']);
                $draft = array_merge($draft, ['brand' => $derived['brand'], 'palette' => $derived['palette'], 'algorithm' => BrandPalette::ALGORITHM]);
            }

            $before = $theme->state();
            $isDefault = $draft['brand'] === null && $draft['logo'] === null && $draft['logo_dark'] === null && $draft['favicon'] === null
                && $draft['font'] === 'plex-arabic' && $draft['appearance'] === 'system';
            $theme->forceFill([
                'published' => $isDefault ? null : $draft, 'draft' => null, 'published_at' => now(), 'published_by' => $actor->id,
                'draft_saved_at' => null, 'draft_saved_by' => null,
            ])->save();
            $this->transitions->record(StateMachine::M35, 'T03', $theme, $before, $isDefault ? 'default' : 'custom', $actor, TransitionRecorder::SOURCE_WEB);
            Audit::record(AuditAction::SiteThemeApplied, $theme, $actor, after: ['brand' => $draft['brand'], 'font' => $draft['font'], 'appearance' => $draft['appearance'], 'default' => $isDefault]);

            return $theme;
        });
    }

    /**
     * M-35-T04: drop the draft only.
     */
    public function discard(User $actor): SiteTheme
    {
        $this->access->authorize($actor, Permission::P100);

        return DB::transaction(function () use ($actor) {
            $theme = SiteTheme::query()->lockForUpdate()->findOrFail(SiteTheme::current()->id);
            if ($theme->draft === null) {
                throw new InvalidTransition(StateMachine::M35, 'T04', 'no_draft');
            }
            $theme->forceFill(['draft' => null, 'draft_saved_at' => null, 'draft_saved_by' => null])->save();
            $this->transitions->record(StateMachine::M35, 'T04', $theme, 'draft', $theme->state(), $actor, TransitionRecorder::SOURCE_WEB);
            Audit::record(AuditAction::SiteThemeDraftDiscarded, $theme, $actor);

            return $theme;
        });
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function assertApplicable(array $draft, SiteTheme $theme): void
    {
        $errors = [];
        if (! array_key_exists($draft['font'], ThemeSettings::FONTS)) {
            $errors['font'] = __('theme.error.font');
        }
        if (! in_array($draft['appearance'], ThemeSettings::APPEARANCES, true)) {
            $errors['appearance'] = __('theme.error.appearance');
        }
        if ($draft['brand_input'] !== null) {
            $derived = BrandPalette::derive((string) $draft['brand_input']);
            if ($derived['status'] === 'rejected') {
                $errors['brand_color'] = __($derived['message']);
            }
        }
        foreach (ThemeSettings::ASSET_KINDS as $kind) {
            if ($draft[$kind] !== null && ! $theme->attachments()->where('uuid', $draft[$kind])->exists()) {
                $errors[$kind] = __('theme.error.asset');
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
