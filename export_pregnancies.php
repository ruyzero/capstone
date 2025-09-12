<?php
require 'vendor/autoload.php'; // or include PhpSpreadsheet manually
require 'db.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Headers
$sheet->setCellValue('A1', 'First Name')
      ->setCellValue('B1', 'Middle Name')
      ->setCellValue('C1', 'Last Name')
      ->setCellValue('D1', 'DOB')
      ->setCellValue('E1', 'Barangay')
      ->setCellValue('F1', 'Purok');

// Get data
$result = $conn->query("
    SELECT p.first_name, p.middle_name, p.last_name, p.dob, b.name AS barangay, pr.name AS purok
    FROM pregnant_women p
    LEFT JOIN barangays b ON p.barangay_id = b.id
    LEFT JOIN puroks pr ON p.purok_id = pr.id
");

$row = 2;
while ($data = $result->fetch_assoc()) {
    $sheet->setCellValue('A' . $row, $data['first_name'])
          ->setCellValue('B' . $row, $data['middle_name'])
          ->setCellValue('C' . $row, $data['last_name'])
          ->setCellValue('D' . $row, $data['dob'])
          ->setCellValue('E' . $row, $data['barangay'])
          ->setCellValue('F' . $row, $data['purok']);
    $row++;
}

// Output
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="pregnancies.xlsx"');

$writer = new Xlsx($spreadsheet);
$writer->save("php://output");
exit;
