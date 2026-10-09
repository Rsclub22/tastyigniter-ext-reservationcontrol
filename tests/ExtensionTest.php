<?php

declare(strict_types=1);

use Igniter\System\Classes\BaseExtension;
use Wagnersnetz\ReservationControl\Extension;

it('registers as a TastyIgniter extension', function (): void {
    expect(new Extension(app()))->toBeInstanceOf(BaseExtension::class);
});
