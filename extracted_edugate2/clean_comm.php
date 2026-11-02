<?php
$content = file_get_contents('communication.html');

// 1. Replace the inner sidebar (settings-menu)
$new_menu = '
                <ul class="settings-menu">
                    <li class="admin-only-nav"><a href="#tab-sms" class="active"><i class="ph ph-chat-circle-text"></i> SMS Settings</a></li>
                    <li class="admin-only-nav"><a href="#tab-message-templates"><i class="ph ph-files"></i> Message Templates</a></li>
                    <li class="admin-only-nav"><a href="#tab-bulk-sms"><i class="ph ph-paper-plane-right"></i> Bulk SMS</a></li>
                </ul>
';
$content = preg_replace('/<ul class="settings-menu">.*?<\/ul>/s', $new_menu, $content);

// 2. Remove all settings sections EXCEPT tab-sms, tab-message-templates, tab-bulk-sms
// The regex below removes a section if its id is NOT tab-sms, tab-message-templates, or tab-bulk-sms.
// To be safe, let's just define the list of sections to remove and use regex for each:
$sections_to_remove = [
    'tab-school', 'tab-schedule', 'tab-holidays', 'tab-weekends', 
    'tab-theme', 'tab-dashboard', 'tab-notifications', 'tab-qrcode', 
    'tab-idcard', 'tab-classes', 'tab-billing', 'tab-scanner-billing', 'tab-rollover'
];

foreach ($sections_to_remove as $sec) {
    // We match from <div id="tab-xyz" ... to the next <!-- Tab --> or <div id="tab- (but not the inner ones)
    // Actually, each tab starts with <div id="tab-xxx" class="settings-section..."> and ends before the next <!-- or end of file.
    // It's easier to just match from `<div id="tab-xyz"` to the start of the next `<!--` or `<div id="tab-`
    $content = preg_replace('/<div id="' . $sec . '" class="settings-section[^>]*>.*?<!--/s', '<!--', $content);
}

// 3. Make the first tab (tab-sms) active by default
$content = str_replace('<div id="tab-sms" class="settings-section admin-only-nav">', '<div id="tab-sms" class="settings-section admin-only-nav active">', $content);
$content = str_replace('<h1>Settings</h1>', '<h1>Communication</h1>', $content);
$content = str_replace('<p>Manage your school system configuration</p>', '<p>Manage SMS and Messaging</p>', $content);
$content = str_replace('<title>Settings - EduGate</title>', '<title>Communication - EduGate</title>', $content);
$content = str_replace('<title>Settings - EduGate School System</title>', '<title>Communication - EduGate School System</title>', $content);

file_put_contents('communication.html', $content);
echo "Cleaned communication.html\n";
