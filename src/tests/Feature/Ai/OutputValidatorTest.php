<?php

use App\Ai\Validation\OutputValidator;

beforeEach(function () {
    $this->v = new OutputValidator;
    $this->schema = [
        'type' => 'object',
        'properties' => [
            'reply' => ['type' => 'string', 'maxLength' => 500],
            'intent' => ['type' => 'string', 'enum' => ['status', 'other']],
            'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
        ],
        'required' => ['reply', 'intent'],
        'additionalProperties' => false,
    ];
    $this->corpus = OutputValidator::corpus([
        'order' => ['number' => 'SO-2026-000123', 'install_date' => '2026-11-20'],
        'due' => ['amount_halalas' => 150000],
        'pay_link' => 'https://app.example.test/pay/abc',
    ]);
    $this->hosts = ['app.example.test'];
});

it('accepts an answer whose numbers, dates and links all come from the context', function () {
    $out = ['reply' => 'طلبك SO-2026-000123: التركيب 20/11/2026 والمتبقي 1,500 ر.س (١٥٠٠). ادفع: https://app.example.test/pay/abc', 'intent' => 'status', 'confidence' => 0.9];

    expect($this->v->validate($out, $this->schema, $this->corpus, $this->hosts))->toBe([]);
});

it('rejects a number or date the context does not hold', function (string $reply) {
    expect($this->v->validate(['reply' => $reply, 'intent' => 'status'], $this->schema, $this->corpus, $this->hosts))
        ->toBe(['$.reply: number or date without a source']);
})->with([
    'invented discount' => 'نقدر نعطيك خصم 15%',
    'other amount' => 'المتبقي 1,200 ر.س',
    'other date' => 'التركيب يوم 2026-11-25',
    'eastern digits' => 'المتبقي ١٢٠٠ ريال',
]);

it('rejects links outside the facility or not in the context', function () {
    $foreign = $this->v->validate(['reply' => 'ادفع هنا https://evil.example.com/pay', 'intent' => 'status'], $this->schema, $this->corpus, $this->hosts);
    $unknown = $this->v->validate(['reply' => 'https://app.example.test/pay/zzz', 'intent' => 'status'], $this->schema, $this->corpus, $this->hosts);

    expect($foreign)->toBe(['$.reply: link outside the facility'])
        ->and($unknown)->toBe(['$.reply: link without a source']);
});

it('allows one-digit numbers and placeholders', function () {
    expect($this->v->validate(['reply' => 'خلال 3 أيام نتصل على [PHONE_12]', 'intent' => 'other'], $this->schema, $this->corpus, $this->hosts))->toBe([]);
});

it('enforces the schema before the facts', function () {
    $problems = $this->v->validate(['reply' => 'x', 'intent' => 'refund', 'extra' => 1, 'confidence' => 2], $this->schema, $this->corpus, $this->hosts);

    expect($problems)->toBe(['$.intent: not an allowed value', '$.extra: not allowed', '$.confidence: above maximum'])
        ->and($this->v->validate(['intent' => 'other'], $this->schema, '', []))->toBe(['$.reply: missing'])
        ->and($this->v->validate('text', $this->schema, '', []))->toBe(['$: expected object']);
});
