<?php

/**
 * Catalog writes through the screens' endpoints: validation, money in integer minor units, variants rules,
 * images through the private attachments service and the audit trail.
 */

use App\Access\Access;
use App\Access\Authorizer;
use App\Audit\AuditAction;
use App\Models\AuditEvent;
use App\Models\Catalog\CatalogMaterial;
use App\Models\Catalog\CatalogProduct;
use App\Models\Catalog\CatalogReadyProduct;
use App\Models\Catalog\CatalogReadyVariant;
use App\Search\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\LicenseFactory;
use Tests\Support\Org;

require_once __DIR__.'/CatalogTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->org = Org::make();
    $this->owner = $this->org->owner;
    $this->actingAs($this->owner);
});

function productPayload(array $overrides = []): array
{
    return [
        'category' => 'مطابخ',
        'name' => ['ar' => 'مطبخ الركن', 'en' => 'Corner kitchen'],
        'description' => ['ar' => 'وصف', 'en' => ''],
        'pricing_basis' => 'linear_meter',
        'reference_price' => '1250.50',
        'option_groups' => [
            ['label' => ['ar' => 'الأجهزة', 'en' => 'Appliances'], 'type' => 'multiple', 'required' => '1', 'values' => [['ar' => 'فرن', 'en' => 'Oven']]],
            ['label' => ['ar' => 'الطول', 'en' => null], 'type' => 'number', 'unit' => 'م'],
        ],
        ...$overrides,
    ];
}

describe('money', function () {
    it('stores a price as integer halalas parsed from text, never a float', function (string $input, int $minor) {
        $this->post(route('catalog.products.store'), productPayload(['reference_price' => $input]))->assertSessionDoesntHaveErrors();

        $product = CatalogProduct::query()->latest('id')->firstOrFail();
        expect($product->reference_price_minor)->toBe($minor)->and($product->getRawOriginal('reference_price_minor'))->toBeInt();
    })->with([
        'whole riyals' => ['1250', 125000],
        'two decimals' => ['1250.50', 125050],
        'one decimal' => ['12.5', 1250],
        'halalas only' => ['0.05', 5],
        'arabic comma' => ['3,25', 325],
    ]);

    it('rejects amounts a person could mistype instead of rounding them', function (string $input) {
        $this->post(route('catalog.products.store'), productPayload(['reference_price' => $input]))->assertSessionHasErrors('reference_price');
        expect(CatalogProduct::query()->count())->toBe(0);
    })->with(['three decimals' => ['1.234'], 'negative' => ['-5'], 'text' => ['abc'], 'exponent' => ['1e3'], 'too large' => ['123456789012']]);

    it('keeps an unknown price as null — never zero', function () {
        $this->post(route('catalog.products.store'), productPayload(['reference_price' => '']))->assertSessionDoesntHaveErrors();

        expect(CatalogProduct::query()->firstOrFail()->reference_price_minor)->toBeNull();
        $this->get(route('catalog.products.index'))->assertInertia(fn (Assert $p) => $p->where('products.data.0.reference_price_minor', null));
    });

    it('sends prices to the screens as integer minor units', function () {
        $ready = makeReady($this->owner);
        $this->post(route('catalog.ready.variants.store', $ready), ['code' => 'a-1', 'price' => '45.5', 'is_default' => '1'])->assertSessionDoesntHaveErrors();

        $variant = CatalogReadyVariant::query()->firstOrFail();
        expect($variant->price_minor)->toBe(4550)->and($variant->code)->toBe('A-1');
        $this->get(route('catalog.ready.edit', $ready))->assertInertia(fn (Assert $p) => $p->where('product.variants_list.0.price_minor', 4550));
    });
});

describe('custom products and materials', function () {
    it('creates a product with option groups and audits it', function () {
        $this->post(route('catalog.products.store'), productPayload())->assertRedirect();

        $product = CatalogProduct::query()->firstOrFail();
        expect($product->name->get('en'))->toBe('Corner kitchen')
            ->and($product->option_groups)->toHaveCount(2)
            ->and($product->option_groups[0]['required'])->toBeTrue()
            ->and($product->option_groups[0]['key'])->not->toBe('')
            ->and($product->option_groups[1]['unit'])->toBe('م')
            ->and($product->versions()->count())->toBe(1);

        $event = AuditEvent::query()->where('action', AuditAction::CatalogProductCreated->value)->firstOrFail();
        expect($event->area)->toBe('catalog')->and($event->actor_user_id)->toBe($this->owner->id);
    });

    it('validates names, price basis and choice groups', function () {
        $this->post(route('catalog.products.store'), productPayload(['name' => ['ar' => ''], 'pricing_basis' => 'bogus']))
            ->assertSessionHasErrors(['name.ar', 'pricing_basis']);
        $this->post(route('catalog.products.store'), productPayload(['option_groups' => [['label' => ['ar' => 'فارغة'], 'type' => 'single']]]))
            ->assertSessionHasErrors('option_groups.0.values');
        $this->post(route('catalog.products.store'), productPayload(['option_groups' => [['label' => ['ar' => 'رقم'], 'type' => 'number']]]))
            ->assertSessionHasErrors('option_groups.0.unit');
        expect(CatalogProduct::query()->count())->toBe(0);
    });

    it('audits update, disable and enable with before and after', function () {
        $product = makeProduct($this->owner);

        $this->put(route('catalog.products.update', $product), productPayload(['reference_price' => '10']))->assertRedirect();
        $this->post(route('catalog.products.disable', $product))->assertRedirect();
        $this->post(route('catalog.products.enable', $product))->assertRedirect();

        $actions = AuditEvent::query()->orderBy('id')->pluck('action')->all();
        expect($actions)->toContain('catalog_product.created', 'catalog_product.updated', 'catalog_product.disabled', 'catalog_product.enabled');

        $updated = AuditEvent::query()->where('action', 'catalog_product.updated')->firstOrFail();
        expect($updated->before['reference_price_minor'])->toBe(125050)->and($updated->after['reference_price_minor'])->toBe(1000);
        $disabled = AuditEvent::query()->where('action', 'catalog_product.disabled')->firstOrFail();
        expect($disabled->before)->toBe(['status' => 'active'])->and($disabled->after)->toBe(['status' => 'disabled']);
    });

    it('filters and paginates the product list on the server', function () {
        foreach (range(1, 17) as $i) {
            makeProduct($this->owner, ['name' => ['ar' => "منتج $i", 'en' => "Item $i"], 'category' => $i % 2 ? 'مطابخ' : 'خزائن']);
        }
        $this->post(route('catalog.products.disable', CatalogProduct::query()->firstOrFail()));

        $this->get(route('catalog.products.index'))->assertInertia(fn (Assert $p) => $p->has('products.data', 15)->where('products.total', 17)->where('products.last_page', 2));
        $this->get(route('catalog.products.index', ['status' => 'disabled']))->assertInertia(fn (Assert $p) => $p->where('products.total', 1));
        $this->get(route('catalog.products.index', ['category' => 'خزائن']))->assertInertia(fn (Assert $p) => $p->where('products.total', 8));
        $this->get(route('catalog.products.index', ['q' => 'Item 17']))->assertInertia(fn (Assert $p) => $p->where('products.total', 1));
        $this->get(route('catalog.products.index', ['q' => '%']))->assertInertia(fn (Assert $p) => $p->where('products.total', 0));
    });

    it('creates, edits and disables a material; the supplier stays out of its version', function () {
        $this->post(route('catalog.materials.store'), [
            'material_group' => 'الأسطح', 'name' => ['ar' => 'رخام كرارا'], 'unit' => 'م²', 'availability' => 'on_request',
            'supplier_name' => 'مصنع الخليج', 'track_quantity' => '1',
        ])->assertRedirect(route('catalog.materials.index'));

        $material = CatalogMaterial::query()->firstOrFail();
        expect($material->track_quantity)->toBeTrue()->and($material->versions()->first()->payload)->not->toHaveKey('supplier_name');

        $this->put(route('catalog.materials.update', $material), ['name' => ['ar' => 'رخام كرارا'], 'unit' => 'م²', 'availability' => 'on_request', 'supplier_name' => 'مورد آخر', 'track_quantity' => '0', 'material_group' => 'الأسطح'])->assertRedirect();
        expect($material->refresh()->supplier_name)->toBe('مورد آخر')->and($material->current_version)->toBe(1);
        expect(AuditEvent::query()->where('action', 'catalog_material.updated')->firstOrFail()->after['supplier_name'])->toBe('مورد آخر');

        $this->post(route('catalog.materials.disable', $material))->assertRedirect();
        expect($material->refresh()->isDisabled())->toBeTrue();
        $this->get(route('catalog.materials.index', ['status' => 'disabled']))->assertInertia(fn (Assert $p) => $p->where('materials.total', 1));
        $this->post(route('catalog.materials.store'), ['name' => ['ar' => 'x'], 'unit' => '', 'availability' => 'nope'])->assertSessionHasErrors(['unit', 'availability']);
    });
});

describe('ready goods and variants', function () {
    it('starts a product as a draft, publishes and stops its sale, auditing each step', function () {
        $this->post(route('catalog.ready.store'), ['name' => ['ar' => 'مقبض'], 'description' => ['ar' => '']])->assertRedirect();
        $ready = CatalogReadyProduct::query()->firstOrFail();
        expect($ready->status->value)->toBe('draft');

        $this->post(route('catalog.ready.status', $ready), ['status' => 'published'])->assertRedirect();
        $this->post(route('catalog.ready.status', $ready), ['status' => 'disabled'])->assertRedirect();
        $this->post(route('catalog.ready.status', $ready), ['status' => 'active'])->assertStatus(422);

        expect($ready->refresh()->isDisabled())->toBeTrue()
            ->and(AuditEvent::query()->where('action', 'catalog_ready.status_changed')->count())->toBe(2);
    });

    it('enforces a unique variant code and the one-default-or-several rule', function () {
        $ready = makeReady($this->owner);
        $other = makeReady($this->owner, 'مقبض آخر');

        $this->post(route('catalog.ready.variants.store', $ready), ['code' => 'HND-1', 'price' => '10', 'name' => ['ar' => 'أسود']])->assertSessionDoesntHaveErrors();
        $this->post(route('catalog.ready.variants.store', $other), ['code' => 'hnd-1', 'price' => '10'])->assertSessionHasErrors('code');
        $this->post(route('catalog.ready.variants.store', $ready), ['code' => 'HND-2', 'price' => '10', 'is_default' => '1'])->assertSessionHasErrors('is_default');
        $this->post(route('catalog.ready.variants.store', $ready), ['code' => 'HND-2', 'price' => '10', 'name' => ['ar' => 'فضي']])->assertSessionDoesntHaveErrors();
        $this->post(route('catalog.ready.variants.store', $other), ['code' => 'HND-3', 'price' => '10', 'is_default' => '1'])->assertSessionDoesntHaveErrors();
        $this->post(route('catalog.ready.variants.store', $other), ['code' => 'HND-4', 'price' => '10'])->assertSessionHasErrors('is_default');
        $this->post(route('catalog.ready.variants.store', $ready), ['code' => 'bad code!', 'price' => '10'])->assertSessionHasErrors('code');
        $this->post(route('catalog.ready.variants.store', $ready), ['code' => 'HND-5', 'price' => '1.999'])->assertSessionHasErrors('price');

        expect(CatalogReadyVariant::query()->count())->toBe(3);
    });

    it('edits a variant without touching a variant of another product, and audits variant changes', function () {
        $ready = makeReady($this->owner);
        $other = makeReady($this->owner, 'مقبض آخر');
        $variant = makeVariant($this->owner, $ready);

        $this->put(route('catalog.ready.variants.update', [$other, $variant]), ['code' => 'HND-60-BLK', 'price' => '1'])->assertNotFound();
        $this->put(route('catalog.ready.variants.update', [$ready, $variant]), ['code' => 'HND-60-BLK', 'price' => '52.50', 'name' => ['ar' => 'أسود مطفي']])->assertSessionDoesntHaveErrors();
        $this->post(route('catalog.ready.variants.status', [$other, $variant]), ['status' => 'published'])->assertNotFound();
        $this->post(route('catalog.ready.variants.status', [$ready, $variant]), ['status' => 'published'])->assertRedirect();

        expect($variant->refresh()->price_minor)->toBe(5250)->and($variant->status->value)->toBe('published');
        expect(AuditEvent::query()->whereIn('action', ['catalog_variant.created', 'catalog_variant.updated', 'catalog_variant.status_changed'])->count())->toBe(3);
    });

    it('does not add variants to a disabled product', function () {
        $ready = makeReady($this->owner);
        $this->post(route('catalog.ready.status', $ready), ['status' => 'disabled']);

        $this->post(route('catalog.ready.variants.store', $ready), ['code' => 'X-1', 'price' => '5', 'is_default' => '1'])->assertSessionHasErrors('product');
    });
});

describe('images', function () {
    it('stores a product image privately and serves it as a signed link that follows access', function () {
        $product = makeProduct($this->owner);
        $png = UploadedFile::fake()->createWithContent('kitchen.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

        $this->post(route('catalog.products.images', $product), ['file' => $png])->assertRedirect();

        $attachment = $product->attachments()->firstOrFail();
        expect($attachment->purpose)->toBe('catalog_image')->and($attachment->toArray())->not->toHaveKeys(['path', 'disk']);
        $this->get(route('catalog.products.edit', $product))->assertInertia(fn (Assert $p) => $p->has('product.images', 1)->where('product.images.0.uuid', $attachment->uuid));

        $url = $this->get(route('catalog.products.edit', $product))->viewData('page')['props']['product']['images'][0]['url'];
        $this->get($url)->assertOk();

        // A user without P-11 holding the link cannot read it (the link is also bound to its user).
        $this->actingAs($this->org->member('finance', [$this->org->a]));
        $this->get($url)->assertForbidden();
    });

    it('refuses non-image files, files above 5 MB and uploads by users without the permission', function () {
        $product = makeProduct($this->owner);
        $material = makeMaterial($this->owner);

        $this->post(route('catalog.products.images', $product), ['file' => UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\n")])->assertSessionHasErrors('file');
        $this->post(route('catalog.products.images', $product), ['file' => UploadedFile::fake()->create('big.png', 6000, 'image/png')])->assertSessionHasErrors('file');

        $this->actingAs($this->org->member('sales', [$this->org->a]));
        $png = UploadedFile::fake()->image('x.png');
        $this->post(route('catalog.materials.images', $material), ['file' => $png])->assertForbidden();
        expect($material->attachments()->count())->toBe(0)->and($product->attachments()->count())->toBe(0);
    });
});

it('finds catalog items in the global search only for people who may manage them', function () {
    makeProduct($this->owner, ['name' => ['ar' => 'مطبخ زيبرا', 'en' => 'Zebra kitchen']]);
    makeMaterial($this->owner, ['name' => ['ar' => 'رخام زيبرا', 'en' => 'Zebra marble']]);
    $ready = makeReady($this->owner, 'مقبض زيبرا');
    app()->setLocale('en');

    $search = fn ($user) => collect(app(SearchService::class)->search($user, 'زيبرا'))->firstWhere('key', 'catalog')['results'] ?? [];
    $types = fn (array $results) => collect($results)->pluck('type')->sort()->values()->all();

    expect($types($search($this->owner)))->toBe(['catalog_material', 'catalog_product', 'catalog_ready'])
        ->and($types($search($this->org->member('sales', [$this->org->a]))))->toBe(['catalog_product'])
        ->and($types($search($this->org->member('inventory', [$this->org->a]))))->toBe(['catalog_material', 'catalog_ready'])
        ->and($search($this->org->member('finance', [$this->org->a])))->toBe([]);

    // Without the `ready_goods` capability the ready section drops out of the results (checked through a request,
    // where the Gate is resolved against the freshly installed license).
    LicenseFactory::install(['capabilities' => ['branch_comparisons']]);
    Access::clearResolvedInstance(Authorizer::class); // the facade caches the Gate of the earlier license
    $groups = $this->actingAs($this->owner)->getJson(route('search.suggest', ['q' => 'زيبرا']))->json('groups');
    $catalog = collect($groups)->firstWhere('key', 'catalog')['results'];

    expect(collect($catalog)->pluck('type')->sort()->values()->all())->toBe(['catalog_material', 'catalog_product'])
        ->and($ready->exists)->toBeTrue();
});
