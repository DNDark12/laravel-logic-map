<?php

$cssContent = file_get_contents(__DIR__ . '/../resources/dist/css/logic-map.css');
$lines = explode("\n", $cssContent);

$split = [
    '01-variables.css'          => array_slice($lines, 0, 197),
    '02-base.css'               => array_slice($lines, 197, 37), // 197 to 233
    '03-topbar.css'             => array_slice($lines, 234, 547), // 234 to 780
    '04-panels.css'             => array_slice($lines, 781, 798), // 781 to 1578
    '05-dropdowns-overlays.css' => array_slice($lines, 1579, 465), // 1579 to 2043
    '06-health-panel.css'       => array_slice($lines, 2044, 497), // 2044 to 2540
    '07-subgraph-mobile.css'    => array_slice($lines, 2541)
];

foreach ($split as $file => $chunk) {
    file_put_contents(__DIR__ . "/../resources/dist/css/$file", implode("\n", $chunk));
    echo "Created $file (" . count($chunk) . " lines)\n";
}
