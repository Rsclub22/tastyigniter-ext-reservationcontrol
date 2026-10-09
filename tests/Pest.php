<?php

declare(strict_types=1);

use Igniter\Flame\Database\Model;
use Igniter\System\Actions\SettingsModel;
use SamPoyigi\Testbench\TestCase;

// Testbench builds a new application (and event dispatcher) for every test, but
// Flame remembers per process which model classes already registered their
// fetch/save event hooks (Model::$eventsBooted). From the second test on those
// hooks are missing from the new dispatcher, for EVERY Flame model, not only
// Settings: afterFetch/beforeSave handlers silently do not run (for Settings
// that shows up as empty reads and SQLSTATE 4025 on save). Production boots
// once per process and is not affected. Reset it for every test.
uses(TestCase::class)->in(__DIR__)->beforeEach(function (): void {
    // flushEventListeners() would instantiate the abstract base class, so reset the flag directly.
    // WARNING: Model::$eventsBooted is a Flame INTERNAL, not API. A framework
    // upgrade that renames or removes it makes this closure throw in beforeEach,
    // i.e. EVERY test fails at once. If that happens, look here first.
    (static function (): void {
        static::$eventsBooted = [];
    })->bindTo(null, Model::class)();
    Model::clearBootedModels();
    SettingsModel::clearInternalCache();
});
