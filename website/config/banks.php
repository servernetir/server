<?php

/*
|--------------------------------------------------------------------------
| بانک‌های ایران — تشخیص از روی شش رقم اول کارت (BIN)
|--------------------------------------------------------------------------
|
| چرا لازم است: سرویس استعلام همیشه نام بانک را برنمی‌گرداند، و وقتی
| برمی‌گرداند املایش ثابت نیست («بانک ملت» / «ملت» / «Mellat»). با BIN،
| تشخیص محلی، آنی و رایگان است و به هیچ سرویسی وابسته نیست.
|
| «color» رنگ رسمی هر بانک است و فقط برای نشانِ یکدست استفاده می‌شود —
| لوگوی واقعی بانک‌ها علامت تجاری است و در پروژه نگهداری نمی‌شود.
|
| «short» یک تا دو نویسه برای نشان. عمداً کوتاه است تا در دایرهٔ ۳۲ پیکسلی
| خوانا بماند.
|
| ⚠ این جدول از فهرست‌های عمومی BIN جمع شده است. اگر کارتی اشتباه تشخیص
| داده شد، ردیفش را اینجا اصلاح کنید — هیچ جای دیگری این نگاشت تکرار نشده.
*/

return [

    'bins' => [
        '603799' => 'melli',
        '589210' => 'sepah',
        '627648' => 'tosee-saderat',
        '207177' => 'tosee-saderat',
        '627961' => 'sanat-madan',
        '603770' => 'keshavarzi',
        '639217' => 'keshavarzi',
        '628023' => 'maskan',
        '627760' => 'postbank',
        '502908' => 'tosee-taavon',
        '627412' => 'eghtesad-novin',
        '622106' => 'parsian',
        '639194' => 'parsian',
        '627884' => 'parsian',
        '502229' => 'pasargad',
        '639347' => 'pasargad',
        '627488' => 'karafarin',
        '502910' => 'karafarin',
        '621986' => 'saman',
        '639346' => 'sina',
        '639607' => 'sarmaye',
        '636214' => 'ayandeh',
        '502806' => 'shahr',
        '504706' => 'shahr',
        '502938' => 'day',
        '603769' => 'saderat',
        '610433' => 'mellat',
        '991975' => 'mellat',
        '589463' => 'refah',
        '627381' => 'ansar',
        '639370' => 'mehr-eghtesad',
        '636949' => 'hekmat',
        '606373' => 'mehr-iran',
        '505785' => 'iranzamin',
        '505416' => 'gardeshgari',
        '505801' => 'kosar',
        '606256' => 'melal',
        '507677' => 'noor',
        '628157' => 'tosee',
        '636795' => 'markazi',
    ],

    'banks' => [
        'melli'          => ['fa' => 'ملی ایران',        'en' => 'Melli',           'short' => 'ملی',  'color' => '#F5A623'],
        'sepah'          => ['fa' => 'سپه',              'en' => 'Sepah',           'short' => 'سپه',  'color' => '#1F4E9C'],
        'tosee-saderat'  => ['fa' => 'توسعه صادرات',     'en' => 'EDBI',            'short' => 'صاد',  'color' => '#0F7B6C'],
        'sanat-madan'    => ['fa' => 'صنعت و معدن',      'en' => 'BIM',             'short' => 'صنع',  'color' => '#2E5E8C'],
        'keshavarzi'     => ['fa' => 'کشاورزی',          'en' => 'Keshavarzi',      'short' => 'کشا',  'color' => '#0E8A4A'],
        'maskan'         => ['fa' => 'مسکن',             'en' => 'Maskan',          'short' => 'مسک',  'color' => '#C0392B'],
        'postbank'       => ['fa' => 'پست بانک',         'en' => 'Post Bank',       'short' => 'پست',  'color' => '#0B7A4B'],
        'tosee-taavon'   => ['fa' => 'توسعه تعاون',      'en' => 'Tosee Taavon',    'short' => 'تعا',  'color' => '#1B6EA8'],
        'eghtesad-novin' => ['fa' => 'اقتصاد نوین',      'en' => 'EN Bank',         'short' => 'نوی',  'color' => '#7B1FA2'],
        'parsian'        => ['fa' => 'پارسیان',          'en' => 'Parsian',         'short' => 'پار',  'color' => '#B0143C'],
        'pasargad'       => ['fa' => 'پاسارگاد',         'en' => 'Pasargad',        'short' => 'پاس',  'color' => '#C8A020'],
        'karafarin'      => ['fa' => 'کارآفرین',         'en' => 'Karafarin',       'short' => 'کار',  'color' => '#1A7A6E'],
        'saman'          => ['fa' => 'سامان',            'en' => 'Saman',           'short' => 'سام',  'color' => '#1560BD'],
        'sina'           => ['fa' => 'سینا',             'en' => 'Sina',            'short' => 'سین',  'color' => '#8E44AD'],
        'sarmaye'        => ['fa' => 'سرمایه',           'en' => 'Sarmaye',         'short' => 'سرم',  'color' => '#2C3E50'],
        'ayandeh'        => ['fa' => 'آینده',            'en' => 'Ayandeh',         'short' => 'آین',  'color' => '#5B2C6F'],
        'shahr'          => ['fa' => 'شهر',              'en' => 'Shahr',           'short' => 'شهر',  'color' => '#C0392B'],
        'day'            => ['fa' => 'دی',               'en' => 'Day',             'short' => 'دی',   'color' => '#0F5E8C'],
        'saderat'        => ['fa' => 'صادرات ایران',     'en' => 'Saderat',         'short' => 'صاد',  'color' => '#1E6BB8'],
        'mellat'         => ['fa' => 'ملت',              'en' => 'Mellat',          'short' => 'ملت',  'color' => '#D4183D'],
        'refah'          => ['fa' => 'رفاه کارگران',     'en' => 'Refah',           'short' => 'رفا',  'color' => '#1D7A8C'],
        'ansar'          => ['fa' => 'انصار',            'en' => 'Ansar',           'short' => 'انص',  'color' => '#4A6741'],
        'mehr-eghtesad'  => ['fa' => 'مهر اقتصاد',       'en' => 'Mehr Eghtesad',   'short' => 'مهر',  'color' => '#2E7D32'],
        'hekmat'         => ['fa' => 'حکمت ایرانیان',    'en' => 'Hekmat',          'short' => 'حکم',  'color' => '#37474F'],
        'mehr-iran'      => ['fa' => 'قرض‌الحسنه مهر',   'en' => 'Mehr Iran',       'short' => 'مهر',  'color' => '#00695C'],
        'iranzamin'      => ['fa' => 'ایران زمین',       'en' => 'Iran Zamin',      'short' => 'ایر',  'color' => '#00838F'],
        'gardeshgari'    => ['fa' => 'گردشگری',          'en' => 'Gardeshgari',     'short' => 'گرد',  'color' => '#EF6C00'],
        'kosar'          => ['fa' => 'کوثر',             'en' => 'Kosar',           'short' => 'کوث',  'color' => '#00796B'],
        'melal'          => ['fa' => 'ملل',              'en' => 'Melal',           'short' => 'ملل',  'color' => '#4527A0'],
        'noor'           => ['fa' => 'نور',              'en' => 'Noor',            'short' => 'نور',  'color' => '#0277BD'],
        'tosee'          => ['fa' => 'توسعه',            'en' => 'Tosee',           'short' => 'توس',  'color' => '#455A64'],
        'markazi'        => ['fa' => 'مرکزی',            'en' => 'Central Bank',    'short' => 'مرک',  'color' => '#263238'],
    ],
];
