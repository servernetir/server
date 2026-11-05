<?php

return [
    'windows' => [
        'title' => 'Windows Hosting | ASP.NET, MSSQL & Plesk',
        'description' => 'Compare Windows hosting plans for ASP.NET and MSSQL with Plesk. Check database quotas, storage and application deployment requirements before ordering.',
        'guide_title' => 'ASP.NET hosting or a Windows VPS?',
        'guide_intro' => 'Match your IIS application and MSSQL requirements to the hosting plan. Shared hosting does not provide full operating-system administration or an RDP desktop.',
        'checks' => [
            'Identify the .NET version and deployment mode: framework-dependent or self-contained. Check runtime compatibility and required IIS settings before ordering.',
            'Check database count, storage allowance and connection requirements against the plan. Connecting with SSMS does not grant Windows administrator access.',
            'For system software, Docker, persistent services or port control, compare VPS plans and the operating systems available at checkout.',
        ],
        'related_label' => 'Compare hosting options',
        'other' => 'Linux hosting for PHP and WordPress',
        'other_slug' => 'linux',
        'vps' => 'Compare hourly VPS costs',
        'contact' => 'Check application compatibility with support',
    ],
    'linux' => [
        'title' => 'Linux Hosting | PHP & WordPress Plans',
        'description' => 'Compare Linux hosting plans for PHP and WordPress. Review storage, database allowances and the control panel terms of each plan before ordering.',
        'guide_title' => 'Choosing Linux hosting for PHP, WordPress and databases',
        'guide_intro' => 'Your computer\'s operating system does not determine the hosting you need. Compare application language, database, resource usage and deployment access with the plan.',
        'checks' => [
            'Check the PHP version and extensions required by your theme, CMS or framework. A control panel name alone does not establish software compatibility.',
            'Compare file storage, database and email quotas, plus resource limits, with actual application usage. Maintain an independent recovery plan.',
            'Compare Windows hosting for IIS or MSSQL dependencies. System software and full service control call for assessing a VPS instead.',
        ],
        'related_label' => 'Compare hosting options',
        'other' => 'Windows hosting for ASP.NET and MSSQL',
        'other_slug' => 'windows',
        'vps' => 'Compare hourly VPS costs',
        'contact' => 'Get help choosing a plan',
    ],
];

