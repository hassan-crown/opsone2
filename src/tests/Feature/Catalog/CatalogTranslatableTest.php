<?php

use App\Models\Catalog\CatalogMaterial;
use App\Support\Translatable\Translated;

it('falls back to the other language when the asked one is empty', function () {
    $both = new Translated('مطبخ', 'Kitchen');
    $onlyAr = Translated::fromArray(['ar' => 'مطبخ', 'en' => '  ']);
    $onlyEn = Translated::fromArray(['ar' => null, 'en' => 'Kitchen']);

    expect($both->get('ar'))->toBe('مطبخ')->and($both->get('en'))->toBe('Kitchen')
        ->and($onlyAr->get('en'))->toBe('مطبخ')->and($onlyAr->only('en'))->toBeNull()
        ->and($onlyEn->get('ar'))->toBe('Kitchen')
        ->and((new Translated)->get('ar'))->toBeNull()
        ->and($both->toArray())->toBe(['ar' => 'مطبخ', 'en' => 'Kitchen']);
});

it('reads the current locale by default', function () {
    app()->setLocale('en');
    expect((new Translated('مطبخ', 'Kitchen'))->get())->toBe('Kitchen');
    app()->setLocale('ar');
    expect((string) new Translated('مطبخ', 'Kitchen'))->toBe('مطبخ');
});

it('casts a JSON column to a value and back, keeping Arabic readable in the database', function () {
    $material = new CatalogMaterial;
    $material->name = ['ar' => 'رخام', 'en' => null];

    expect($material->getAttributes()['name'])->toBe('{"ar":"رخام","en":null}')
        ->and($material->name)->toBeInstanceOf(Translated::class)
        ->and($material->name->get('en'))->toBe('رخام');

    $material->description = null;
    expect($material->description->isEmpty())->toBeTrue();
});
