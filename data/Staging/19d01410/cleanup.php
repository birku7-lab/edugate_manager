<?php
// 1. Clean communication.html
$content = file_get_contents('communication.html');

// Replace settings sidebar menu with communication menu
$comm_menu = '
                <ul class="settings-menu">
                    <li><a href="#tab-sms" class="active"><i class="ph ph-chat-circle-text"></i> SMS Settings</a></li>
                    <li><a href="#tab-message-templates"><i class="ph ph-files"></i> Message Templates</a></li>
                    <li><a href="#tab-bulk-sms"><i class="ph ph-paper-plane-right"></i> Bulk SMS</a></li>
                </ul>
';
$content = preg_replace('/<ul class="settings-menu">.*?<\/ul>/s', $comm_menu, $content);

// Remove all settings sections except the three sms ones
// It's easier to just use regex to delete known sections or build the content.
$sections_to_remove = [
    'tab-school', 'tab-schedule', 'tab-holidays', 'tab-weekends', 
    'tab-theme', 'tab-dashboard', 'tab-notifications', 'tab-qrcode', 
    'tab-idcard', 'tab-classes', 'tab-billing', 'tab-scanner-billing', 'tab-rollover'
];

foreach ($sections_to_remove as $sec) {
    // Regex to remove <div id="tab-xxx" class="settings-section..."> ... up to the next <!-- Tab --> or </div>\s*<!--
    // Actually, each section has an ID. Let's match from <div id="tab-..." to the next <div id="tab-..."
    // Because HTML parsing with regex is hard, let's just do a simple str_replace if possible.
}
file_put_contents('communication.html', $content);
