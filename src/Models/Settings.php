<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl\Models;

use Igniter\Flame\Database\Model;
use Igniter\System\Actions\SettingsModel;

/**
 * @method static mixed get(string $key, mixed $default = null)
 * @method static bool set(string|array $key, mixed $value = null)
 *
 * @mixin SettingsModel
 */
class Settings extends Model
{
    public array $implement = [SettingsModel::class];

    public string $settingsCode = 'wagnersnetz_reservationcontrol_settings';

    public string $settingsFieldsConfig = 'settings';
}
