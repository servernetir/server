<?php

$catalog = json_decode(file_get_contents(__DIR__.'/../database/catalog/hardware-presentation-20261014.json'), true, 512, JSON_THROW_ON_ERROR);
foreach (json_decode(file_get_contents(__DIR__.'/../database/catalog/hardware-growth-20261123.json'), true, 512, JSON_THROW_ON_ERROR) as $record) {
    $catalog[$record['slug']] ??= ['manufacturer_url' => $record['reference']];
}
foreach (json_decode(file_get_contents(__DIR__.'/../database/catalog/datacenter-equipment-20261124.json'), true, 512, JSON_THROW_ON_ERROR) as $record) {
    $catalog[$record['slug']] ??= ['manufacturer_url' => $record['reference']] + ($record['presentation'] ?? []);
}
return $catalog;
