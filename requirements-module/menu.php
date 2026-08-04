<?php

return [
    [
        'label'      => 'Requirements',
        'icon'       => 'fas fa-list-check',
        'controller' => 'requirements',
        'url'        => 'requirements',
        'roles'      => \Roles::idsByNames(['admin', 'operator']),
    ],
];
