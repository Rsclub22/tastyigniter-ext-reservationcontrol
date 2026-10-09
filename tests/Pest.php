<?php

declare(strict_types=1);

use SamPoyigi\Testbench\TestCase;
use Wagnersnetz\ReservationControl\Models\Settings;

uses(TestCase::class)->in(__DIR__);

/**
 * Give the Settings model a clean slate.
 *
 * Testbench builds a new application (and event dispatcher) for every test, but
 * Flame remembers per process that a model class already registered its
 * fetch/save event hooks (Model::$eventsBooted). From the second test on, the
 * hooks are missing from the new dispatcher: reads never fill the settings
 * values and saves insert an empty row (SQLSTATE 4025). Production boots once
 * per process, so it is not affected.
 */
function resetSettingsState(): void
{
    Settings::flushEventListeners();
    Settings::clearBootedModels();
    Settings::clearInternalCache();
}
