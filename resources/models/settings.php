<?php

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
        ],
    ],
];
