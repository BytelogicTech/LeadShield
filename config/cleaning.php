<?php

/**
 * Cleaning defaults. Lists ported from docs/reference/lead_cleaner.php.
 */

return [

    'free_provider_policy' => 'allow',

    'max_per_company_domain' => 1,

    'chunk_size' => (int) env('LEADSHIELD_CLEAN_CHUNK_SIZE', 500),

    'role_localparts' => [
        'info', 'contact', 'contactus', 'contact-us', 'hello', 'hi', 'admin', 'administrator', 'office', 'mail',
        'email', 'enquiry', 'enquiries', 'inquiry', 'inquiries', 'sales', 'support', 'help', 'helpdesk', 'service',
        'services', 'customerservice', 'customercare', 'care', 'team', 'hr', 'jobs', 'careers', 'career',
        'recruitment', 'hiring', 'accounts', 'accounting', 'billing', 'finance', 'invoice', 'invoices', 'marketing',
        'media', 'press', 'pr', 'news', 'newsletter', 'webmaster', 'web', 'website', 'postmaster', 'hostmaster',
        'abuse', 'spam', 'security', 'privacy', 'legal', 'compliance', 'noc', 'it', 'noreply', 'no-reply',
        'donotreply', 'do-not-reply', 'mailer-daemon', 'root', 'system', 'notifications', 'alerts', 'reception',
        'frontdesk', 'booking', 'bookings', 'reservations', 'orders', 'order', 'shop', 'store', 'feedback',
        'general', 'all', 'staff', 'everyone', 'management', 'operations', 'ops', 'purchase', 'purchasing',
        'procurement', 'tender', 'tenders', 'test', 'testing', 'demo', 'example', 'user', 'username', 'null',
        'none', 'na', 'nobody',
    ],

    'free_providers' => [
        'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.in', 'yahoo.co.uk', 'ymail.com', 'rocketmail.com',
        'hotmail.com', 'hotmail.co.uk', 'outlook.com', 'live.com', 'msn.com', 'aol.com', 'icloud.com', 'me.com',
        'mac.com', 'gmx.com', 'gmx.de', 'mail.com', 'zoho.com', 'zohomail.com', 'protonmail.com', 'proton.me',
        'yandex.com', 'yandex.ru', 'rediffmail.com', 'inbox.com', 'qq.com', '163.com', '126.com', 'web.de',
        'tutanota.com',
    ],

    'known_typos' => [
        'gmial.com', 'gmal.com', 'gmaill.com', 'gmali.com', 'gamil.com', 'gnail.com', 'gmail.co', 'gmail.cm',
        'gmail.con', 'gmai.com', 'gmil.com', 'gmaii.com', 'hotmal.com', 'hotmial.com', 'hotmai.com', 'hotmail.co',
        'hotmail.con', 'hotamil.com', 'outlok.com', 'outloo.com', 'outlook.co', 'outlook.con', 'yahooo.com',
        'yaho.com', 'yahoo.co', 'yahoo.con', 'yhoo.com', 'iclod.com', 'icloud.co', 'aol.co',
    ],

    'disposable_or_fake' => [
        'mailinator.com', 'guerrillamail.com', '10minutemail.com', 'tempmail.com', 'temp-mail.org', 'yopmail.com',
        'trashmail.com', 'sharklasers.com', 'getnada.com', 'dispostable.com', 'maildrop.cc', 'fakeinbox.com',
        'throwawaymail.com', 'mintemail.com', 'mohmal.com', 'emailondeck.com', 'example.com', 'example.org',
        'example.net', 'test.com', 'domain.com', 'email.com', 'yourdomain.com', 'company.com', 'abc.com', 'xyz.com',
        'sample.com', 'invalid.com', 'localhost',
    ],

    'mx_providers' => [
        'mimecast' => 'Mimecast',
        'pphosted' => 'Proofpoint',
        'ppe-hosted' => 'Proofpoint',
        'proofpoint' => 'Proofpoint',
        'barracudanetworks' => 'Barracuda',
        'barracuda' => 'Barracuda',
        'iphmx' => 'Cisco IronPort',
        'messagelabs' => 'Symantec/Broadcom',
        'trendmicro' => 'Trend Micro',
        'sophos' => 'Sophos',
        'forcepoint' => 'Forcepoint',
        'fortimail' => 'FortiMail',
        'spamtitan' => 'SpamTitan',
        'mailcontrol' => 'Forcepoint',
        'trustwave' => 'Trustwave',
        'hornetsecurity' => 'Hornetsecurity',
        'antispamcloud' => 'SpamExperts',
        'protection.outlook.com' => 'Microsoft 365',
        'google.com' => 'Google Workspace',
        'googlemail.com' => 'Google Workspace',
        'zoho' => 'Zoho',
    ],

    'secure_gateways' => [
        'Mimecast', 'Proofpoint', 'Barracuda', 'Cisco IronPort', 'Symantec/Broadcom', 'Trend Micro', 'Sophos',
        'Forcepoint', 'FortiMail', 'SpamTitan', 'Trustwave', 'Hornetsecurity', 'SpamExperts',
    ],

    'email_regex' => '/^(?=.{6,254}$)(?=.{1,64}@)[a-z0-9](?:[a-z0-9._%+\'-]*[a-z0-9])?@(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/',

];
