<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
$x_0001 = file_get_contents('communication.html');

$x_0002 = strpos($x_0001, '<ul class="settings-menu">');
$x_0003 = strpos($x_0001, '</ul>', $x_0002) + 5;

$x_0004 = '<ul class="settings-menu">
                    <li><a href="#tab-sms" class="active"><i class="ph ph-chat-circle-text"></i> SMS Settings</a></li>
                    <li><a href="#tab-message-templates"><i class="ph ph-files"></i> Message Templates</a></li>
                    <li><a href="#tab-bulk-sms"><i class="ph ph-paper-plane-right"></i> Bulk SMS</a></li>
                </ul>';

$x_0001 = substr_replace($x_0001, $x_0004, $x_0002, $x_0003 - $x_0002);

$x_0005 = strpos($x_0001, '<div id="tab-sms"');
$x_0006 = strpos($x_0001, '<!-- Message Templates Tab -->');

$x_0007 = strpos($x_0001, '<div id="tab-message-templates"');
$x_0008 = strpos($x_0001, '<!-- Bulk SMS Tab -->');

$x_0009 = strpos($x_0001, '<div id="tab-bulk-sms"');
$x_000a = strpos($x_0001, '<!-- Theme Tab -->');

$x_000b = substr($x_0001, $x_0005, $x_0006 - $x_0005) . "\n" . 
             substr($x_0001, $x_0007, $x_0008 - $x_0007) . "\n" . 
             substr($x_0001, $x_0009, $x_000a - $x_0009);

$x_000c = strpos($x_0001, '<div class="settings-content">');
if ($x_000c === false) {
    echo "Could not find settings-content div\n";
    exit;
}

$x_000d = strpos($x_0001, '</div> <!-- End settings-content -->');
if ($x_000d === false) {

    $x_000d = strpos($x_0001, '</div>', strpos($x_0001, '<!-- Theme Tab -->'));

    $x_000d = strrpos($x_0001, '</div>', strrpos($x_0001, '<script'));
}

$x_000c = strpos($x_0001, '<div class="settings-content">') + strlen('<div class="settings-content">');
$x_000d = strpos($x_0001, '</main>', $x_000c);

$x_000e = "\n" . $x_000b . "\n            </div>\n        </div>\n    ";
$x_0001 = substr_replace($x_0001, $x_000e, $x_000c, $x_000d - $x_000c);

$x_0001 = str_replace('<h1><i class="ph ph-gear"></i> System Settings</h1>', '<h1><i class="ph ph-chat-circle-text"></i> Communication</h1>', $x_0001);
$x_0001 = str_replace('<p>Configure how your attendance system operates</p>', '<p>Manage SMS, Message Templates and Bulk Messaging</p>', $x_0001);
$x_0001 = str_replace('<title>Settings - EduGate School System</title>', '<title>Communication - EduGate School System</title>', $x_0001);
$x_0001 = str_replace('<div id="tab-sms" class="settings-section admin-only-nav">', '<div id="tab-sms" class="settings-section admin-only-nav active">', $x_0001);

file_put_contents('communication.html', $x_0001);
echo "Done\n";
