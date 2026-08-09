<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Tests\Support\ImportFixtureBuilder;

$targetDir = __DIR__.'/../samples/import';

if (! is_dir($targetDir) && ! mkdir($targetDir, 0775, true) && ! is_dir($targetDir)) {
    fwrite(STDERR, "Unable to create {$targetDir}\n");
    exit(1);
}

$xlsxSource = ImportFixtureBuilder::createXlsx([
    ['Section', 'Field', 'Type', 'Required', 'Options'],
    ['Personal Information', 'Full Name', 'text', 'yes', ''],
    ['Personal Information', 'Email', 'email', 'yes', ''],
    ['Personal Information', 'Phone Number', 'text', 'no', ''],
    ['Employment Details', 'Department', 'select', 'yes', 'Engineering,Sales,HR'],
    ['Employment Details', 'Joining Date', 'date', 'yes', ''],
], 'employee-registration.xlsx');

$docxSource = ImportFixtureBuilder::createDocx('Personal Information', [
    ['Field', 'Type', 'Required', 'Options'],
    ['Full Name', 'text', 'yes', ''],
    ['Email', 'email', 'yes', ''],
    ['Department', 'select', 'yes', 'Engineering,Sales,HR'],
    ['Joining Date', 'date', 'yes', ''],
], 'employee-registration.docx');

$targets = [
    $xlsxSource => $targetDir.'/employee-registration.xlsx',
    $docxSource => $targetDir.'/employee-registration.docx',
];

foreach ($targets as $source => $destination) {
    if (! copy($source, $destination)) {
        fwrite(STDERR, "Failed to copy {$source} to {$destination}\n");
        exit(1);
    }

    @unlink($source);
    echo "Created {$destination}\n";
}
