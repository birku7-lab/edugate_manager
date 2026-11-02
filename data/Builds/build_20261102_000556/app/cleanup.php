<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
$x_0001 = file_get_contents('communication.html');

$x_0002 = '
                <ul class="settings-menu">
                    <li><a href="#tab-sms" class="active"><i class="ph ph-chat-circle-text"></i> SMS Settings</a></li>
                    <li><a href="#tab-message-templates"><i class="ph ph-files"></i> Message Templates</a></li>
                    <li><a href="#tab-bulk-sms"><i class="ph ph-paper-plane-right"></i> Bulk SMS</a></li>
                </ul>
';
$x_0001 = preg_replace('/<ul class="settings-menu">.*?<\/ul>/s', $x_0002, $x_0001);

$x_0003 = [
    'tab-school', 'tab-schedule', 'tab-holidays', 'tab-weekends', 
    'tab-theme', 'tab-dashboard', 'tab-notifications', 'tab-qrcode', 
    'tab-idcard', 'tab-classes', 'tab-billing', 'tab-scanner-billing', 'tab-rollover'
];

foreach ($x_0003 as $x_0004) {

}
file_put_contents('communication.html', $x_0001);
