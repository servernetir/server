<?php

/*
|--------------------------------------------------------------------------
| کاتالوگ محصولات غیرهاست — vps / dedicated / cloud / domain
|--------------------------------------------------------------------------
| هر دسته در فایل جدا در config/catalog/ نگهداری می‌شود.
| pool امکانات و FAQ مشترک از config/hosting.php خوانده می‌شود.
*/

return [
    'vps'       => require __DIR__.'/catalog/vps.php',
    'dedicated' => require __DIR__.'/catalog/dedicated.php',
    'cloud'     => require __DIR__.'/catalog/cloud.php',
    'domain'    => require __DIR__.'/catalog/domain.php',
];
