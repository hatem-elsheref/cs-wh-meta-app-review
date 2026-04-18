<?php

use App\Models\MetaSetting;

test('normalize strips ACCESS_TOKEN= env prefix', function () {
    expect(MetaSetting::normalizeAccessToken('ACCESS_TOKEN=EAAR_test_token_suffix'))
        ->toBe('EAAR_test_token_suffix');
});

test('normalize strips Bearer prefix', function () {
    expect(MetaSetting::normalizeAccessToken('Bearer EAAR_x'))->toBe('EAAR_x');
});

test('normalize strips env then Bearer', function () {
    expect(MetaSetting::normalizeAccessToken('ACCESS_TOKEN=Bearer EAAR_y'))->toBe('EAAR_y');
});

test('normalize leaves plain token unchanged', function () {
    expect(MetaSetting::normalizeAccessToken('EAAR_plain'))->toBe('EAAR_plain');
});
