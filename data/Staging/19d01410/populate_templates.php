<?php
$html = file_get_contents('communication.html');

// We want to extract the placeholder="e.g. ..." and put the text (without the "e.g. ") inside the <textarea>...</textarea>
$html = preg_replace_callback('/<textarea([^>]*)placeholder="e\.g\.\s*([^"]+)"([^>]*)><\/textarea>/i', function($matches) {
    $attrs1 = $matches[1];
    $placeholder_text = $matches[2];
    $attrs2 = $matches[3];
    return '<textarea' . $attrs1 . 'placeholder="e.g. ' . $placeholder_text . '"' . $attrs2 . '>' . $placeholder_text . '</textarea>';
}, $html);

// Handle the ones without "e.g. " placeholders if they have any, but we can just set them manually if they don't match.
// Exam / Report Card SMS:
$html = str_replace('<textarea name="sms_template_exam" class="form-control" rows="2"></textarea>', '<textarea name="sms_template_exam" class="form-control" rows="2">Term {Current Term} results for {Student Name} are out. Please collect the report card from the school.</textarea>', $html);

// General Announcements:
$html = str_replace('<textarea name="sms_template_announcement" class="form-control" rows="2"></textarea>', '<textarea name="sms_template_announcement" class="form-control" rows="2">Dear {Parent Name}, please note this important update from {School Name}.</textarea>', $html);

file_put_contents('communication.html', $html);
echo "Updated default values in templates.\n";
