<?php

$shop = json_decode(file_get_contents(__DIR__.'/../database/catalog/hardware-guidance-20261014.json'), true, 512, JSON_THROW_ON_ERROR);
foreach (require __DIR__.'/datacenter_content.php' as $category => $content) {
    $shop['guides'][$category] = array_map(fn ($row) => ['title' => $row['h'], 'body' => $row['p']], $content['guide']);
}
return $shop;
