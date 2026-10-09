<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

it('resolves a label in english', function (): void {
    app()->setLocale('en');
    expect(trans('reservationcontrol::default.label_full_day'))->toBe('All day');
});

it('resolves the same label in german', function (): void {
    app()->setLocale('de');
    expect(trans('reservationcontrol::default.label_full_day'))->toBe('Ganzer Tag');
});

it('falls back to english for an unsupported locale', function (): void {
    app()->setLocale('fr');
    expect(trans('reservationcontrol::default.label_full_day'))
        ->not->toBe('reservationcontrol::default.label_full_day')
        ->and(trans('reservationcontrol::default.label_full_day'))->toBe('All day');
});

it('carries the same keys in english and german', function (): void {
    $en = array_keys(Arr::dot(require __DIR__.'/../resources/lang/en/default.php'));
    $de = array_keys(Arr::dot(require __DIR__.'/../resources/lang/de/default.php'));

    expect($de)->toEqualCanonicalizing($en);
});

it('keeps the placeholders identical in both languages', function (): void {
    $en = Arr::dot(require __DIR__.'/../resources/lang/en/default.php');
    $de = Arr::dot(require __DIR__.'/../resources/lang/de/default.php');

    foreach ($en as $key => $text) {
        preg_match_all('/:[a-z_]+/', $text, $enMatches);
        preg_match_all('/:[a-z_]+/', $de[$key], $deMatches);

        expect(array_unique($deMatches[0]))->toEqualCanonicalizing(array_unique($enMatches[0]), $key);
    }
});
