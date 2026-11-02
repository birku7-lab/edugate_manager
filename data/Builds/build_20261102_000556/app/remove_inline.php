<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
$x_0001 = file_get_contents('communication.html');

$x_0001 = preg_replace('/<script>[\s\S]*?<\/script>/', '', $x_0001);
file_put_contents('communication.html', $x_0001);
echo "Removed inline script\n";
