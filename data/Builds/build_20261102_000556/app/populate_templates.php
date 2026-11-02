<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
$x_0001 = file_get_contents('communication.html');

$x_0001 = preg_replace_callback('/<textarea([^>]*)placeholder="e\.g\.\s*([^"]+)"([^>]*)><\/textarea>/i', function($x_0002) {
    $x_0003 = $x_0002[1];
    $x_0004 = $x_0002[2];
    $x_0005 = $x_0002[3];
    return '<textarea' . $x_0003 . 'placeholder="e.g. ' . $x_0004 . '"' . $x_0005 . '>' . $x_0004 . '</textarea>';
}, $x_0001);

$x_0001 = str_replace('<textarea name="sms_template_exam" class="form-control" rows="2"></textarea>', '<textarea name="sms_template_exam" class="form-control" rows="2">Term {Current Term} results for {Student Name} are out. Please collect the report card from the school.</textarea>', $x_0001);

$x_0001 = str_replace('<textarea name="sms_template_announcement" class="form-control" rows="2"></textarea>', '<textarea name="sms_template_announcement" class="form-control" rows="2">Dear {Parent Name}, please note this important update from {School Name}.</textarea>', $x_0001);

file_put_contents('communication.html', $x_0001);
echo "Updated default values in templates.\n";
