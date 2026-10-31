<?php
declare(strict_types=1);

// Tests of the importer module (modules/importer/tests/WordPressRealTest.php) run with the core suite under this name: the core
// runner takes the class named after the file, the module runner the original class, so every test runs once in each.
require_once __DIR__ . '/../modules/importer/tests/WordPressRealTest.php';

class_alias(WordPressRealTest::class, 'ImporterWordPressRealTest');
