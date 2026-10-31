<?php
declare(strict_types=1);

// Tests of the importer module (modules/importer/tests/TildaRealTest.php) run with the core suite under this name: the core
// runner takes the class named after the file, the module runner the original class, so every test runs once in each.
require_once __DIR__ . '/../modules/importer/tests/TildaRealTest.php';

class_alias(TildaRealTest::class, 'ImporterTildaRealTest');
