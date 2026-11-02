<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
$html = file_get_contents('communication.html');

$html = preg_replace_callback('/<textarea([^>]*)placeholder="e\.g\.\s*([^"]+)"([^>]*)><\/textarea>/i', function($matches) {
    $attrs1 = $matches[1];
    $placeholder_text = $matches[2];
    $attrs2 = $matches[3];
    return '<textarea' . $attrs1 . 'placeholder="e.g. ' . $placeholder_text . '"' . $attrs2 . '>' . $placeholder_text . '</textarea>';
}, $html);

$html = str_replace('<textarea name="sms_template_exam" class="form-control" rows="2"></textarea>', '<textarea name="sms_template_exam" class="form-control" rows="2">Term {Current Term} results for {Student Name} are out. Please collect the report card from the school.</textarea>', $html);

$html = str_replace('<textarea name="sms_template_announcement" class="form-control" rows="2"></textarea>', '<textarea name="sms_template_announcement" class="form-control" rows="2">Dear {Parent Name}, please note this important update from {School Name}.</textarea>', $html);

file_put_contents('communication.html', $html);
echo "Updated default values in templates.\n";
