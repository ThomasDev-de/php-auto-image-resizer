<?php
use Webcito\ImageCache;

require_once "vendor/autoload.php";

$options = [
    'resolution' => $_COOKIE['resolution'] ?? null
];
new ImageCache($options);

