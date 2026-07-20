<?php

/**
 * Backend sidebar menu registry. Each new backend resource gets an entry
 * here instead of hand-editing the layout — this is step 4 of the
 * "add a new table" workflow (create table -> generate model -> build
 * controller/views -> add a menu entry here).
 *
 * 'roles' is a list of role ids allowed to see the item, or null for any
 * authenticated backend user. Ties into ControllerBase::$allowedRoles.
 */
return [
    [
        'label'      => 'Dashboard',
        'icon'       => 'fas fa-tachometer-alt',
        'controller' => 'index',
        'url'        => 'backend',
        'roles'      => null,
    ],
    [
        'label'      => 'Items',
        'icon'       => 'fas fa-list',
        'controller' => 'items',
        'url'        => 'backend/items',
        'roles'      => null,
    ],
    [
        'label'      => 'Users',
        'icon'       => 'fas fa-users',
        'controller' => 'users',
        'url'        => 'backend/users',
        'roles'      => [1],
    ],
    [
        'label'      => 'Roles',
        'icon'       => 'fas fa-user-shield',
        'controller' => 'roles',
        'url'        => 'backend/roles',
        'roles'      => [1],
    ],
    [
        'label'      => 'Settings',
        'icon'       => 'fas fa-cogs',
        'controller' => 'settings',
        'url'        => 'backend/settings',
        'roles'      => [1],
    ],
    [
        'label'      => 'API Keys (Internal)',
        'icon'       => 'fas fa-key',
        'controller' => 'api-keys',
        'url'        => 'backend/api-keys',
        'roles'      => null,
    ],
    [
        'label'      => 'External Connections',
        'icon'       => 'fas fa-plug',
        'controller' => 'external-connections',
        'url'        => 'backend/external-connections',
        'roles'      => [1],
    ],
    [
        'label'      => 'Cron',
        'icon'       => 'fas fa-clock',
        'controller' => 'cron',
        'url'        => 'backend/cron',
        'roles'      => [1],
    ],
    [
        'label'      => 'Audit Log',
        'icon'       => 'fas fa-clipboard-list',
        'controller' => 'audit-log',
        'url'        => 'backend/audit-log',
        'roles'      => [1],
    ],
    [
        'label'      => 'System Log',
        'icon'       => 'fas fa-bug',
        'controller' => 'system-log',
        'url'        => 'backend/system-log',
        'roles'      => [1],
    ],
];
