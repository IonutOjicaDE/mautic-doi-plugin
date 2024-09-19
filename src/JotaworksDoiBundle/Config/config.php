<?php

return [
    'name'        => 'JotaWorks Double-Opt-In',
    'description' => 'Adds a robust and flexible way to add a double-opt-in process (DOI) to any form in Mautic.',
    'version'     => '1.4.2',
    'author'      => 'Sebastian Fahrenkrog (Content Optimizer GmbH)',
    'services' => [
        'events' => [
            'jw.mautic.email.formbundle.subscriber' => [
                'class'     => \MauticPlugin\JotaworksDoiBundle\EventListener\FormSubscriber::class,
                'arguments' => [
                    'router',
                    'event_dispatcher',
                    'mautic.helper.encryption',
                    'mautic.email.model.email',
                    'mautic.lead.model.lead',
                    'mautic.tracker.contact'
                ]
            ],
            'jw.mautic.email.report.doi' => [
                'class'     => \MauticPlugin\JotaworksDoiBundle\EventListener\DoiReportSubscriber::class,
                'arguments' => [
                    'mautic.lead.reportbundle.fields_builder',
                ],
            ],
            'jw.mautic.webhook.subscriber' => [
                'class'     => \MauticPlugin\JotaworksDoiBundle\EventListener\WebhookSubscriber::class,
                'arguments' => [
                    'mautic.webhook.model.webhook',
                ],
            ],
            'jw.mautic.queue.subscriber' => [
                'class'     => \MauticPlugin\JotaworksDoiBundle\EventListener\QueueSubscriber::class,
                'arguments' => [
                    'monolog.logger.mautic','jw.doi.actionhelper','jw.doi.nothumanclickhelper'
                ],
            ]
        ],
        'forms' => [
            'jw.mautic.form.type.jw_emailsend_list' => [
                'class'     => \MauticPlugin\JotaworksDoiBundle\Form\Type\EmailSendType::class,
                'arguments' => ['mautic.factory','translator']
,            ],
        ],
        'helpers' => [
            'jw.doi.actionhelper' => [
                'class' => \MauticPlugin\JotaworksDoiBundle\Helper\DoiActionHelper::class,
                'arguments' => [
                  'event_dispatcher',
                  'mautic.helper.ip_lookup',
                  'mautic.page.model.page',
                  'mautic.email.model.email',
                  'mautic.core.model.auditlog',
                  'mautic.lead.model.lead',
                  'mautic.lead.model.field',
                  'doctrine.orm.entity_manager',
                  'monolog.logger.mautic',
                  'request_stack' ]
            ],
            'jw.doi.nothumanclickhelper' => [
                'class'     => \MauticPlugin\JotaworksDoiBundle\Helper\NotHumanClickHelper::class,
                'arguments' => ['mautic.helper.paths' ]
            ]

        ]
    ],
    'routes' => [
        'public' => [
            'jotaworks_doiauth_index' => [
                'path'       => '/doi/{enc}',
                'controller' => 'JotaworksDoiBundle:Doi:index'
            ],
            'jotaworks_doiauth_nothuman' => [
                'path'       => '/nothuman/{hash}',
                'controller' => 'JotaworksDoiBundle:Doi:nothuman'
            ]
        ]
    ]
];
