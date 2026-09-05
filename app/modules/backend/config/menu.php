<?php

/**
 * Backend sidebar menu registry. Each new backend resource gets an entry
 * here instead of hand-editing the layout — this is step 4 of the
 * "add a new table" workflow (create table -> generate model -> build
 * controller/views -> add a menu entry here).
 *
 * 'roles' is a list of role ids allowed to see the item, or null for any
 * authenticated backend user. Ties into ControllerBase::$allowedRoles.
 *
 * 'group' (optional) nests an item under a collapsible sidebar dropdown
 * labeled with that string, rather than rendering it as its own top-level
 * link — added once the flat list grew long enough to cover the whole
 * sidebar height. Omit it for a top-level item (Dashboard, Items,
 * Tickets). A module's own menu.php contribution (ModuleManager::menu())
 * doesn't need to set this — an omitted 'group' renders top-level, same
 * as before this existed, so existing module menu files keep working
 * unchanged.
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
        'label'      => 'My Profile',
        'icon'       => 'fas fa-id-badge',
        'controller' => 'account',
        'url'        => 'backend/account',
        'roles'      => null,
        'group'      => 'Users',
    ],
    [
        'label'      => 'Users',
        'icon'       => 'fas fa-users',
        'controller' => 'users',
        'url'        => 'backend/users',
        'roles'      => [1],
        'group'      => 'Users',
    ],
    [
        'label'      => 'Roles',
        'icon'       => 'fas fa-user-shield',
        'controller' => 'roles',
        'url'        => 'backend/roles',
        'roles'      => [1],
        'group'      => 'Users',
    ],
    [
        'label'      => 'API Keys (Internal)',
        'icon'       => 'fas fa-key',
        'controller' => 'api-keys',
        'url'        => 'backend/api-keys',
        'roles'      => null,
        'group'      => 'Users',
    ],
    [
        'label'      => 'System Settings',
        'icon'       => 'fas fa-cogs',
        'controller' => 'settings',
        'url'        => 'backend/settings',
        'roles'      => [1],
        'group'      => 'Settings',
    ],
    [
        'label'      => 'Configuration',
        'icon'       => 'fas fa-sliders-h',
        'controller' => 'configuration',
        'url'        => 'backend/configuration',
        'roles'      => [1],
        'group'      => 'Settings',
    ],
    [
        'label'      => 'Cron',
        'icon'       => 'fas fa-clock',
        'controller' => 'cron',
        'url'        => 'backend/cron',
        'roles'      => [1],
        'group'      => 'Settings',
    ],
    [
        'label'      => 'External Connections',
        'icon'       => 'fas fa-plug',
        'controller' => 'external-connections',
        'url'        => 'backend/external-connections',
        'roles'      => [1],
        'group'      => 'Settings',
    ],
    [
        'label'      => 'Tickets',
        'icon'       => 'fas fa-ticket-alt',
        'controller' => 'tickets',
        'url'        => 'backend/tickets',
        'roles'      => \Roles::idsByNames(['admin', 'operator']),
    ],
    [
        'label'      => 'Audit Log',
        'icon'       => 'fas fa-clipboard-list',
        'controller' => 'audit-log',
        'url'        => 'backend/audit-log',
        'roles'      => [1],
        'group'      => 'Logs',
    ],
    [
        'label'      => 'System Log',
        'icon'       => 'fas fa-bug',
        'controller' => 'system-log',
        'url'        => 'backend/system-log',
        'roles'      => [1],
        'group'      => 'Logs',
    ],
    [
        'label'      => 'Error Log',
        'icon'       => 'fas fa-exclamation-triangle',
        'controller' => 'error-log',
        'url'        => 'backend/error-log',
        'roles'      => [1],
        'group'      => 'Logs',
    ],
];
