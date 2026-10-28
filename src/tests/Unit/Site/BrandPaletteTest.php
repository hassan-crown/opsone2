<?php

/**
 * THM-03 / THM-07: the palette derived from one brand colour keeps WCAG AA in light, dark and contrast modes.
 * A colour that cannot be read is refused with a bilingual message; a nearby fixable colour is auto-adjusted.
 */

use App\Site\Theme\BrandPalette;
use App\Site\Theme\Color;
use App\Site\Theme\ThemeSettings;
use Tests\TestCase;

uses(TestCase::class);

it('derives the default Alpha colour unchanged and passes every pair', function () {
    $d = BrandPalette::derive(BrandPalette::DEFAULT_COLOR);

    expect($d['status'])->toBeIn(['ok', 'adjusted'])
        ->and($d['palette'])->toHaveKeys(['light', 'dark', 'contrast'])
        ->and(collect($d['report'])->every(fn ($r) => $r['pass']))->toBeTrue();
});

it('every preset passes WCAG AA in all three modes', function (string $hex) {
    $d = BrandPalette::derive($hex);

    expect($d['status'])->not->toBe('rejected')
        ->and(collect($d['report'])->where('pass', false)->all())->toBe([]);
})->with(array_values(BrandPalette::PRESETS));

it('refuses colours that are too light to carry text, with a translatable message', function (string $hex) {
    $d = BrandPalette::derive($hex);

    expect($d['status'])->toBe('rejected')
        ->and($d['palette'])->toBeNull()
        ->and($d['message'])->toBe('theme.error.too_light');
})->with(['#FFFF00', '#FFFFFF', '#F5F5DC', '#FFEB3B']);

it('refuses invalid input', function (string $value) {
    expect(BrandPalette::derive($value)['status'])->toBe('rejected')
        ->and(BrandPalette::derive($value)['message'])->toBe('theme.error.invalid_color');
})->with(['red', '#12345', '#GGGGGG', '', 'javascript:alert(1)', '#0E7C72;}body{display:none']);

it('auto-adjusts a mid-light colour and the adjusted brand reads with white text', function () {
    $d = BrandPalette::derive('#3FB8A9');

    expect($d['status'])->toBe('adjusted')
        ->and($d['message'])->toBe('theme.notice.adjusted')
        ->and(Color::contrast('#FFFFFF', $d['brand']))->toBeGreaterThanOrEqual(4.5)
        ->and($d['brand'])->not->toBe('#3FB8A9');
});

it('never returns an accepted palette that fails a pair, for any colour', function () {
    mt_srand(8);
    for ($i = 0; $i < 300; $i++) {
        $hex = sprintf('#%02X%02X%02X', mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
        $d = BrandPalette::derive($hex);

        if ($d['status'] !== 'rejected') {
            expect(collect($d['report'])->where('pass', false)->all())->toBe([], $hex);
            expect(Color::contrast('#FFFFFF', $d['palette']['light']['brand']))->toBeGreaterThanOrEqual(4.5);
            expect(Color::contrast($d['palette']['dark']['brand'], '#0F1D31'))->toBeGreaterThanOrEqual(4.5);
            expect(Color::contrast('#FFFFFF', $d['palette']['contrast']['brand']))->toBeGreaterThanOrEqual(7.0);
        }
    }
});

it('injects only validated hex values into the style block', function () {
    $d = BrandPalette::derive('#1D5FA8');
    $css = ThemeSettings::css(['font' => 'plex-sans', 'palette' => $d['palette']]);

    expect($css)->toContain("[data-surface='site']")
        ->and($css)->toContain('--brand:')
        ->and($css)->not->toContain('<')
        ->and(ThemeSettings::css(['palette' => ['light' => ['brand' => 'red;}body{x:y', 'evil role' => '#FFFFFF']]]))->toBe('');
});

it('keeps the default theme free of overrides', function () {
    expect(ThemeSettings::css(ThemeSettings::defaults()))->toBe('');
});
