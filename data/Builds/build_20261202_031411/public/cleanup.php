<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
$content = file_get_contents('communication.html');

$comm_menu = '
                <ul class="settings-menu">
                    <li><a href="#tab-sms" class="active"><i class="ph ph-chat-circle-text"></i> SMS Settings</a></li>
                    <li><a href="#tab-message-templates"><i class="ph ph-files"></i> Message Templates</a></li>
                    <li><a href="#tab-bulk-sms"><i class="ph ph-paper-plane-right"></i> Bulk SMS</a></li>
                </ul>
';
$content = preg_replace('/<ul class="settings-menu">.*?<\/ul>/s', $comm_menu, $content);

$sections_to_remove = [
    'tab-school', 'tab-schedule', 'tab-holidays', 'tab-weekends', 
    'tab-theme', 'tab-dashboard', 'tab-notifications', 'tab-qrcode', 
    'tab-idcard', 'tab-classes', 'tab-billing', 'tab-scanner-billing', 'tab-rollover'
];

foreach ($sections_to_remove as $sec) {

}
file_put_contents('communication.html', $content);
