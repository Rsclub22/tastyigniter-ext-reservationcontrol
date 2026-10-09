<?php

declare(strict_types=1);

use Igniter\System\Classes\BaseExtension;
use Wagnersnetz\ReservationControl\Extension;
use Wagnersnetz\ReservationControl\Models\Settings;

it('registers as a TastyIgniter extension', function (): void {
    expect(new Extension(app()))->toBeInstanceOf(BaseExtension::class);
});

it('registers the settings page with its permission', function (): void {
    $settings = (new Extension(app()))->registerSettings();

    expect($settings['settings']['model'])->toBe(Settings::class)
        ->and($settings['settings']['permissions'])->toContain('Wagnersnetz.ReservationControl.ManageSettings');
});

it('registers the manage settings permission', function (): void {
    expect(array_keys((new Extension(app()))->registerPermissions()))
        ->toContain('Wagnersnetz.ReservationControl.ManageSettings');
});
