<?php

$staffPermissions = [
    'orders.view', 'orders.process', 'inventory.view', 'inventory.request',
    'returns.request', 'returns.record_payment', 'returns.dispatch',
];

return [
    'staff_allowed_permissions' => $staffPermissions,
    'default_permissions' => [
        'staff' => $staffPermissions,
        'manager' => [
            ...$staffPermissions,
            'orders.assign', 'inventory.approve', 'inventory.reorder',
            'products.manage', 'customers.view', 'reports.view', 'forecast.manage',
            'returns.approve', 'suppliers.manage', 'staff_reports.view', 'audit.view',
            'returns.resolve', 'returns.reconcile', 'reports.profit', 'branches.manage',
        ],
    ],
];
