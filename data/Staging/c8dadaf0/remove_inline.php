<?php
$html = file_get_contents('communication.html');
// Remove inline script at the end
$html = preg_replace('/<script>[\s\S]*?<\/script>/', '', $html);
file_put_contents('communication.html', $html);
echo "Removed inline script\n";
