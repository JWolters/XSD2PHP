<?php
/**
 * generate PHP-classes from XSD
 */

include __DIR__.'/XSD2PHP.php';
// prevent warning
date_default_timezone_set('Europe/Amsterdam');

$XSD2PHP = new XSD2PHP();
//$XSD2PHP->analyze('Job.xsd');
$XSD2PHP->parse('Job.xsd');