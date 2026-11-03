<?php

return [

    'menu' => [
        ['label' => 'Dashboard', 'route' => 'admin.dashboard', 'icon' => 'home', 'match' => 'admin.dashboard'],

        ['heading' => 'Learning'],
        ['label' => 'Courses', 'route' => 'admin.courses.index', 'icon' => 'book', 'match' => 'admin.courses.*'],
        ['label' => 'Batches', 'route' => 'admin.batches.index', 'icon' => 'layers', 'match' => 'admin.batches.*'],
        ['label' => 'Categories', 'route' => 'admin.categories.index', 'icon' => 'tag', 'match' => 'admin.categories.*'],
        ['label' => 'Content library', 'route' => 'admin.library.content.index', 'icon' => 'video', 'match' => 'admin.library.content.*'],
        ['label' => 'Resource library', 'route' => 'admin.library.resources.index', 'icon' => 'folder', 'match' => 'admin.library.resources.*'],

        ['heading' => 'People'],
        ['label' => 'Students', 'route' => 'admin.students.index', 'icon' => 'users', 'match' => 'admin.students.*'],
        ['label' => 'Instructors', 'route' => 'admin.instructors.index', 'icon' => 'user', 'match' => 'admin.instructors.*'],

        ['heading' => 'Sales'],
        ['label' => 'Orders', 'route' => 'admin.orders.index', 'icon' => 'credit-card', 'match' => 'admin.orders.*'],
        ['label' => 'Payments', 'route' => 'admin.payments.index', 'icon' => 'inbox', 'match' => 'admin.payments.*'],
        ['label' => 'Vouchers', 'route' => 'admin.vouchers.index', 'icon' => 'gift', 'match' => 'admin.vouchers.*'],
        ['label' => 'Combo offers', 'route' => 'admin.combos.index', 'icon' => 'percent', 'match' => 'admin.combos.*'],

        ['heading' => 'Communication'],
        ['label' => 'SMS composer', 'route' => 'admin.communication.sms.create', 'icon' => 'send', 'match' => 'admin.communication.sms.*'],
        ['label' => 'Email composer', 'route' => 'admin.communication.email.create', 'icon' => 'mail', 'match' => 'admin.communication.email.*'],
        ['label' => 'Web phone', 'route' => 'admin.communication.softphone.index', 'icon' => 'phone', 'match' => 'admin.communication.softphone.*'],

        ['heading' => 'Affiliate'],
        ['label' => 'Affiliates', 'route' => 'admin.affiliates.index', 'icon' => 'users', 'match' => 'admin.affiliates.*'],
        ['label' => 'Settings', 'route' => 'admin.affiliate-settings.edit', 'icon' => 'link', 'match' => 'admin.affiliate-settings.*'],

        ['heading' => 'Security'],
        ['label' => 'Device Limit', 'route' => 'admin.device-limits.index', 'icon' => 'sliders', 'match' => 'admin.device-limits.*'],
        ['label' => 'Login Info', 'route' => 'admin.login-infos.index', 'icon' => 'lock', 'match' => 'admin.login-infos.*'],

        ['heading' => 'System'],
        ['label' => 'Logs', 'route' => 'admin.logs.index', 'icon' => 'file', 'match' => 'admin.logs.*'],
        ['label' => 'Server', 'route' => 'admin.server.index', 'icon' => 'monitor', 'match' => 'admin.server.*'],
        ['label' => 'Settings', 'route' => 'admin.settings.index', 'icon' => 'sliders', 'match' => 'admin.settings.*'],
    ],

];
