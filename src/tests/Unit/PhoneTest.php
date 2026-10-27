<?php

use App\Customers\Phone;

it('normalizes Saudi mobiles to E.164 from every common format', function (string $input) {
    expect(Phone::normalize($input))->toBe('+966551903418');
})->with(['0551903418', '551903418', '+966 55 190 3418', '00966551903418', '966551903418', '٠٥٥١٩٠٣٤١٨', '055-190-3418']);

it('rejects malformed Saudi mobiles', function (string $input) {
    expect(Phone::normalize($input))->toBeNull();
})->with(['', 'abc', '055190341', '04551903418', '+96611465229']);

it('accepts landlines only when mobile-only is off', function () {
    expect(Phone::normalize('011 465 2290'))->toBeNull()
        ->and(Phone::normalize('011 465 2290', false))->toBe('+966114652290');
});

it('keeps foreign numbers in E.164 and requires an international prefix for them', function () {
    expect(Phone::normalize('+971 50 123 4567'))->toBe('+971501234567')
        ->and(Phone::normalize('+0123'))->toBeNull();
});
