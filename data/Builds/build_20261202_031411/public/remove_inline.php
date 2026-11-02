<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
$html = file_get_contents('communication.html');

$html = preg_replace('/<script>[\s\S]*?<\/script>/', '', $html);
file_put_contents('communication.html', $html);
echo "Removed inline script\n";
