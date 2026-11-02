<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
$x_0001 = glob("*.html");

$x_0002 = '
            <div class="nav-section admin-only-nav">
                <div class="nav-section-title">COMMUNICATION</div>
                <ul class="sidebar-menu">
                    <li><a href="communication.html#tab-sms"><i class="ph ph-chat-circle-text"></i> SMS Settings</a></li>
                    <li><a href="communication.html#tab-message-templates"><i class="ph ph-files"></i> Message Templates</a></li>
                    <li><a href="communication.html#tab-bulk-sms"><i class="ph ph-paper-plane-right"></i> Bulk SMS</a></li>
                    <li><a href="sms_logs.html"><i class="ph ph-list"></i> SMS Logs</a></li>
                </ul>
            </div>
';

foreach ($x_0001 as $x_0003) {
    $x_0004 = file_get_contents($x_0003);
    if (strpos($x_0004, '<div class="nav-section-title">COMMUNICATION</div>') !== false) {

    }
    
    if (strpos($x_0004, '<div class="nav-section-title">COMMUNICATION</div>') === false) {
        $x_0004 = preg_replace('/<li(?: class="admin-only-nav")?><a href="sms_logs\.html"><i class="ph ph-chat-text"><\/i> SMS Logs<\/a><\/li>/', '', $x_0004);
        
        $x_0004 = preg_replace('/<div class="nav-section">\s*<div class="nav-section-title">(OTHER|SYSTEM)<\/div>/', $x_0002 . '            <div class="nav-section">' . "\n" . '                <div class="nav-section-title">$1</div>', $x_0004);
        
        file_put_contents($x_0003, $x_0004);
    }
}
echo "Updated HTML sidebars.\n";
