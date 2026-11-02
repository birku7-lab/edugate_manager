import os
import re

html_files = [f for f in os.listdir('.') if f.endswith('.html')]

sidebar_addition = """
            <div class="nav-section admin-only-nav">
                <div class="nav-section-title">COMMUNICATION</div>
                <ul class="sidebar-menu">
                    <li><a href="communication.html#tab-sms"><i class="ph ph-chat-circle-text"></i> SMS Settings</a></li>
                    <li><a href="communication.html#tab-message-templates"><i class="ph ph-files"></i> Message Templates</a></li>
                    <li><a href="communication.html#tab-bulk-sms"><i class="ph ph-paper-plane-right"></i> Bulk SMS</a></li>
                    <li><a href="sms_logs.html"><i class="ph ph-list"></i> SMS Logs</a></li>
                </ul>
            </div>"""

for file in html_files:
    with open(file, 'r', encoding='utf-8') as f:
        content = f.read()

    # If already added, skip
    if "div class=\"nav-section-title\">COMMUNICATION</div>" in content:
        continue

    # Remove sms_logs from OTHER
    content = re.sub(r'<li class="admin-only-nav"><a href="sms_logs\.html"><i class="ph ph-chat-text"></i> SMS Logs</a></li>', '', content)
    content = re.sub(r'<li><a href="sms_logs\.html"><i class="ph ph-chat-text"></i> SMS Logs</a></li>', '', content)

    # Add COMMUNICATION section after FINANCE
    content = content.replace(
        '<div class="nav-section">\n                <div class="nav-section-title">OTHER</div>',
        sidebar_addition + '\n            <div class="nav-section">\n                <div class="nav-section-title">OTHER</div>'
    )
    
    with open(file, 'w', encoding='utf-8') as f:
        f.write(content)
    
print("Updated all HTML files.")
