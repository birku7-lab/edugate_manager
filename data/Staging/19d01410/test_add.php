<?php
require 'api/config.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['full_name'] = 'Test';
$_POST['admission_number'] = '123456';
$_POST['class'] = '8';
$_POST['gender'] = 'Male';
$_POST['parent_name'] = 'Test Parent';
$_POST['parent_phone'] = '+254712345678';
$_POST['parent_relationship'] = 'Father';
require 'api/students.php';
