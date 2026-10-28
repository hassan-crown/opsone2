<?php

use App\Catalog\MinorUnits;

it('parses decimal text to integer minor units with string arithmetic', function (string $input, int $minor) {
    expect(MinorUnits::fromInput($input))->toBe($minor);
})->with([['0', 0], ['0.05', 5], ['7', 700], ['12.5', 1250], ['1250.50', 125050], ['3,25', 325], [' 99.99 ', 9999], ['0.1', 10]]);

it('rejects anything that is not at most two decimals', function (mixed $input) {
    expect(MinorUnits::isValidInput($input))->toBeFalse();
})->with(['', '1.234', '-1', '1e3', 'abc', '1.', '.5', '123456789012', null, 12.5, [[]]]);

it('formats minor units back to the input form without floats', function (int $minor, string $text) {
    expect(MinorUnits::toInput($minor))->toBe($text);
})->with([[0, '0.00'], [5, '0.05'], [1250, '12.50'], [125050, '1250.50']]);

it('does not lose a halala where binary floats would (0.07 and 19.99)', function () {
    expect(MinorUnits::fromInput('0.07'))->toBe(7)->and(MinorUnits::fromInput('19.99'))->toBe(1999)->and(MinorUnits::fromInput('1.15'))->toBe(115);
});
