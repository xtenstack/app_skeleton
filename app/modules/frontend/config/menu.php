<?php

/**
 * Frontend sidebar menu — deliberately hand-written, not
 * ModuleManager::mergedMenu('frontend') (see sidenav.phtml): that method
 * always includes backend's menu.php as its base regardless of $surface,
 * which would leak the entire admin menu onto a member's own dashboard.
 * Worth fixing in ModuleManager itself once a second frontend-surface
 * module actually needs to contribute a menu item — not needed yet with
 * only this one static entry.
 */
return [
    [
        'label'      => 'Dashboard',
        'icon'       => 'fas fa-home',
        'controller' => 'dashboard',
        'url'        => 'frontend/dashboard',
        'roles'      => null,
    ],
    [
        'label'      => 'Support Requests',
        'icon'       => 'fas fa-ticket-alt',
        'controller' => 'tickets',
        'url'        => 'frontend/tickets',
        'roles'      => null,
    ],
    [
        'label'      => 'My Profile',
        'icon'       => 'fas fa-id-badge',
        'controller' => 'account',
        'url'        => 'backend/account',
        'roles'      => null,
    ],
];
