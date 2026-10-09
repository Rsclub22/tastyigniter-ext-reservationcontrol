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

    expect(array_values(array_diff($en, $de)))->toBe([], 'missing in de: '.implode(', ', array_diff($en, $de)));
    expect(array_values(array_diff($de, $en)))->toBe([], 'missing in en: '.implode(', ', array_diff($de, $en)));
});

it('keeps placeholders, plural syntax and embedded markup identical in both languages', function (): void {
    $en = Arr::dot(require __DIR__.'/../resources/lang/en/default.php');
    $de = Arr::dot(require __DIR__.'/../resources/lang/de/default.php');

    $placeholders = function (string $text): array {
        preg_match_all('/:[A-Za-z_][A-Za-z0-9_]*/', $text, $m);
        $found = array_unique($m[0]);
        sort($found);

        return $found;
    };

    // The plural selector: "{1} ...|[2,*] ..." must have the same segments.
    $plural = function (string $text): array {
        preg_match_all('/(?:^|\|)(\{\d+\}|\[[^\]]*\])/', $text, $m);

        return $m[1];
    };

    // Embedded HTML tags (the hint_* strings are rendered unescaped).
    $tags = function (string $text): array {
        preg_match_all('/<\/?[a-z][^>]*>/i', $text, $m);

        return $m[0];
    };

    foreach ($en as $key => $text) {
        expect($placeholders($de[$key]))->toBe($placeholders($text), "placeholders differ in $key");
        expect($plural($de[$key]))->toBe($plural($text), "plural syntax differs in $key");
        expect($tags($de[$key]))->toBe($tags($text), "embedded markup differs in $key");
    }
});

it('allows embedded HTML only in hint_* strings (and console tags in console_*)', function (): void {
    $en = Arr::dot(require __DIR__.'/../resources/lang/en/default.php');

    foreach ($en as $key => $text) {
        // console_* strings carry Symfony console tags such as <options=bold>.
        if (! str_starts_with($key, 'hint_') && ! str_starts_with($key, 'console_')) {
            expect(preg_match('/<\/?[a-z][^>]*>/i', $text))->toBe(0, "$key carries markup but is not a hint_* string");
        }
    }
});

it('lists german entries identical to the english ones for a human to check', function (): void {
    $en = Arr::dot(require __DIR__.'/../resources/lang/en/default.php');
    $de = Arr::dot(require __DIR__.'/../resources/lang/de/default.php');

    // Identical pairs can be legitimate (a name, a unit); this only keeps them
    // visible. Add a key here once a human has confirmed it.
    $confirmed = [
        'label_optional', 'col_name', 'col_persons', 'max_pax_entry', 'console_field_name', 'console_field_status',
        'console_col_name', 'console_col_status', 'console_col_persons', 'console_ask_name', 'console_ask_persons',
        'console_ask_status', 'console_import_run', 'console_reservation_line',
    ];

    $identical = array_values(array_diff(
        array_keys(array_filter($en, fn (string $text, string $key): bool => $de[$key] === $text, ARRAY_FILTER_USE_BOTH)),
        $confirmed,
    ));

    expect($identical)->toBe([], 'identical en/de entries, check for untranslated copies: '.implode(', ', $identical));
});
