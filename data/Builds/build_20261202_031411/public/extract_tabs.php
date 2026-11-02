<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
$html = file_get_contents('communication.html');

$menu_start = strpos($html, '<ul class="settings-menu">');
$menu_end = strpos($html, '</ul>', $menu_start) + 5;

$new_menu = '<ul class="settings-menu">
                    <li><a href="#tab-sms" class="active"><i class="ph ph-chat-circle-text"></i> SMS Settings</a></li>
                    <li><a href="#tab-message-templates"><i class="ph ph-files"></i> Message Templates</a></li>
                    <li><a href="#tab-bulk-sms"><i class="ph ph-paper-plane-right"></i> Bulk SMS</a></li>
                </ul>';

$html = substr_replace($html, $new_menu, $menu_start, $menu_end - $menu_start);

$tab_sms_start = strpos($html, '<div id="tab-sms"');
$tab_sms_end = strpos($html, '<!-- Message Templates Tab -->');

$tab_msg_start = strpos($html, '<div id="tab-message-templates"');
$tab_msg_end = strpos($html, '<!-- Bulk SMS Tab -->');

$tab_bulk_start = strpos($html, '<div id="tab-bulk-sms"');
$tab_bulk_end = strpos($html, '<!-- Theme Tab -->');

$tabs_html = substr($html, $tab_sms_start, $tab_sms_end - $tab_sms_start) . "\n" . 
             substr($html, $tab_msg_start, $tab_msg_end - $tab_msg_start) . "\n" . 
             substr($html, $tab_bulk_start, $tab_bulk_end - $tab_bulk_start);

$content_start = strpos($html, '<div class="settings-content">');
if ($content_start === false) {
    echo "Could not find settings-content div\n";
    exit;
}

$content_end = strpos($html, '</div> <!-- End settings-content -->');
if ($content_end === false) {

    $content_end = strpos($html, '</div>', strpos($html, '<!-- Theme Tab -->'));

    $content_end = strrpos($html, '</div>', strrpos($html, '<script'));
}

$content_start = strpos($html, '<div class="settings-content">') + strlen('<div class="settings-content">');
$content_end = strpos($html, '</main>', $content_start);

$new_content = "\n" . $tabs_html . "\n            </div>\n        </div>\n    ";
$html = substr_replace($html, $new_content, $content_start, $content_end - $content_start);

$html = str_replace('<h1><i class="ph ph-gear"></i> System Settings</h1>', '<h1><i class="ph ph-chat-circle-text"></i> Communication</h1>', $html);
$html = str_replace('<p>Configure how your attendance system operates</p>', '<p>Manage SMS, Message Templates and Bulk Messaging</p>', $html);
$html = str_replace('<title>Settings - EduGate School System</title>', '<title>Communication - EduGate School System</title>', $html);
$html = str_replace('<div id="tab-sms" class="settings-section admin-only-nav">', '<div id="tab-sms" class="settings-section admin-only-nav active">', $html);

file_put_contents('communication.html', $html);
echo "Done\n";
