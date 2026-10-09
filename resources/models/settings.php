<?php

use Wagnersnetz\ReservationControl\DailySheet;
use Wagnersnetz\ReservationControl\Extension;
use Wagnersnetz\ReservationControl\Http\Middleware\InternalNetworkOnly;
use Wagnersnetz\ReservationControl\Rooms;
use Wagnersnetz\ReservationControl\TableAllocator;

return [
    'form' => [
        'toolbar' => [
            'buttons' => [
                'save' => ['label' => 'lang:admin::lang.button_save', 'class' => 'btn btn-primary', 'data-request' => 'onSave'],
                'saveClose' => [
                    'label' => 'lang:admin::lang.button_save_close',
                    'class' => 'btn btn-default',
                    'data-request' => 'onSave',
                    'data-request-data' => 'close:1',
                ],
            ],
        ],
        'fields' => [
            'large_party_threshold' => [
                'label' => 'lang:reservationcontrol::default.label_large_party_threshold',
                'comment' => 'lang:reservationcontrol::default.help_large_party_threshold',
                'type' => 'number',
                'default' => 20,
            ],
            'large_party_open' => [
                'label' => 'lang:reservationcontrol::default.label_large_party_open',
                'comment' => 'lang:reservationcontrol::default.help_large_party_window',
                'span' => 'left',
                'type' => 'text',
                'placeholder' => 'HH:MM',
                'default' => '10:00',
            ],
            'large_party_close' => [
                'label' => 'lang:reservationcontrol::default.label_large_party_close',
                'comment' => 'lang:reservationcontrol::default.help_large_party_window',
                'span' => 'right',
                'type' => 'text',
                'placeholder' => 'HH:MM',
                'default' => '22:00',
            ],
            'large_party_all_weekdays' => [
                'label' => 'lang:reservationcontrol::default.label_large_party_all_weekdays',
                'comment' => 'lang:reservationcontrol::default.help_large_party_all_weekdays',
                'type' => 'switch',
                'default' => true,
            ],
            'large_party_skip_table_check' => [
                'label' => 'lang:reservationcontrol::default.label_large_party_skip_table_check',
                'comment' => 'lang:reservationcontrol::default.help_large_party_skip_table_check',
                'type' => 'switch',
                'default' => true,
            ],
            'internal_booking_horizon_days' => [
                'label' => 'lang:reservationcontrol::default.label_internal_booking_horizon_days',
                'comment' => 'lang:reservationcontrol::default.help_internal_booking_horizon_days',
                'type' => 'number',
                'default' => 365,
            ],
            'split_time' => [
                'label' => 'lang:reservationcontrol::default.label_split_time',
                'comment' => 'lang:reservationcontrol::default.help_split_time',
                'type' => 'text',
                'placeholder' => '15:00',
                'default' => '',
            ],
            'max_print_range_days' => [
                'label' => 'lang:reservationcontrol::default.label_max_print_range_days',
                'comment' => 'lang:reservationcontrol::default.help_max_print_range_days',
                'type' => 'number',
                'default' => DailySheet::DEFAULT_MAX_DAYS,
            ],
            'turnover_buffer_minutes' => [
                'label' => 'lang:reservationcontrol::default.label_turnover_buffer_minutes',
                'comment' => 'lang:reservationcontrol::default.help_turnover_buffer_minutes',
                'type' => 'number',
                'default' => TableAllocator::DEFAULT_TURNOVER_BUFFER_MINUTES,
            ],
            'max_tables_per_reservation' => [
                'label' => 'lang:reservationcontrol::default.label_max_tables_per_reservation',
                'comment' => 'lang:reservationcontrol::default.help_max_tables_per_reservation',
                'type' => 'number',
                'default' => TableAllocator::DEFAULT_MAX_TABLES_PER_RESERVATION,
            ],
            'rooms_area_name' => [
                'label' => 'lang:reservationcontrol::default.label_rooms_area_name',
                'comment' => 'lang:reservationcontrol::default.help_rooms_area_name',
                'type' => 'text',
                'default' => Rooms::DEFAULT_AREA,
            ],
            'max_name_length' => [
                'label' => 'lang:reservationcontrol::default.label_max_name_length',
                'comment' => 'lang:reservationcontrol::default.help_max_name_length',
                'type' => 'number',
                'default' => Extension::DEFAULT_MAX_NAME_LENGTH,
            ],
            'max_email_length' => [
                'label' => 'lang:reservationcontrol::default.label_max_email_length',
                'comment' => 'lang:reservationcontrol::default.help_max_email_length',
                'type' => 'number',
                'default' => Extension::DEFAULT_MAX_EMAIL_LENGTH,
            ],
            'max_phone_length' => [
                'label' => 'lang:reservationcontrol::default.label_max_phone_length',
                'comment' => 'lang:reservationcontrol::default.help_max_phone_length',
                'type' => 'number',
                'default' => Extension::DEFAULT_MAX_PHONE_LENGTH,
            ],
            'phone_pattern' => [
                'label' => 'lang:reservationcontrol::default.label_phone_pattern',
                'comment' => 'lang:reservationcontrol::default.help_phone_pattern',
                'type' => 'text',
                'default' => Extension::DEFAULT_PHONE_PATTERN,
            ],
            'public_form_fields' => [
                'label' => 'lang:reservationcontrol::default.label_public_form_fields',
                'comment' => 'lang:reservationcontrol::default.help_public_form_fields',
                'type' => 'textarea',
                'default' => implode("\n", Extension::DEFAULT_PUBLIC_FORM_FIELDS),
            ],
            'phone_required_public' => [
                'label' => 'lang:reservationcontrol::default.label_phone_required_public',
                'comment' => 'lang:reservationcontrol::default.help_phone_required_public',
                'type' => 'switch',
                'default' => true,
            ],
            'reply_to_address' => [
                'label' => 'lang:reservationcontrol::default.label_reply_to_address',
                'comment' => 'lang:reservationcontrol::default.help_reply_to_address',
                'type' => 'text',
                'placeholder' => 'info@example.com',
                'default' => '',
            ],
            'reply_to_name' => [
                'label' => 'lang:reservationcontrol::default.label_reply_to_name',
                'comment' => 'lang:reservationcontrol::default.help_reply_to_name',
                'type' => 'text',
                'default' => '',
            ],
            'admin_rate_limit' => [
                'label' => 'lang:reservationcontrol::default.label_admin_rate_limit',
                'comment' => 'lang:reservationcontrol::default.help_admin_rate_limit',
                'type' => 'text',
                'placeholder' => '30,1',
                'default' => '',
            ],
            'trusted_proxies' => [
                'label' => 'lang:reservationcontrol::default.label_trusted_proxies',
                'comment' => 'lang:reservationcontrol::default.help_trusted_proxies',
                'type' => 'textarea',
                'default' => implode("\n", Extension::DEFAULT_TRUSTED_PROXIES),
            ],
            'internal_allowed_networks' => [
                'label' => 'lang:reservationcontrol::default.label_internal_allowed_networks',
                'comment' => 'lang:reservationcontrol::default.help_internal_allowed_networks',
                'type' => 'textarea',
                'default' => implode("\n", InternalNetworkOnly::DEFAULT_ALLOWED),
            ],
            'cutoff_hours_before_closing' => [
                'label' => 'lang:reservationcontrol::default.label_cutoff_hours_before_closing',
                'comment' => 'lang:reservationcontrol::default.help_cutoff_hours_before_closing',
                'type' => 'number',
                'default' => 0,
            ],
            'apply_max_guests_online' => [
                'label' => 'lang:reservationcontrol::default.label_apply_max_guests_online',
                'comment' => 'lang:reservationcontrol::default.help_apply_max_guests_online',
                'type' => 'switch',
                'default' => false,
            ],
            'allow_online_on_blocked_default' => [
                'label' => 'lang:reservationcontrol::default.label_allow_online_on_blocked_default',
                'comment' => 'lang:reservationcontrol::default.help_allow_online_on_blocked_default',
                'type' => 'switch',
                'default' => false,
            ],
        ],
    ],
];
