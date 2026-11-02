<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
$html_files = glob("*.html");

$sidebar_addition = '
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

foreach ($html_files as $file) {
    $content = file_get_contents($file);
    if (strpos($content, '<div class="nav-section-title">COMMUNICATION</div>') !== false) {

    }
    
    if (strpos($content, '<div class="nav-section-title">COMMUNICATION</div>') === false) {
        $content = preg_replace('/<li(?: class="admin-only-nav")?><a href="sms_logs\.html"><i class="ph ph-chat-text"><\/i> SMS Logs<\/a><\/li>/', '', $content);
        
        $content = preg_replace('/<div class="nav-section">\s*<div class="nav-section-title">(OTHER|SYSTEM)<\/div>/', $sidebar_addition . '            <div class="nav-section">' . "\n" . '                <div class="nav-section-title">$1</div>', $content);
        
        file_put_contents($file, $content);
    }
}
echo "Updated HTML sidebars.\n";
