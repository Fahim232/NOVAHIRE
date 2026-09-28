<?php
/**
 * NovaHire — Master Test Runner
 *
 * Runs all unit, integration, end-to-end, and security suites in sequence.
 */

echo "======================================================================\n";
echo "              NOVAHIRE MASTER TEST SUITE RUNNER                       \n";
echo "======================================================================\n\n";

$suites = [
    'AI CV Analyzer & Screening System' => __DIR__ . '/test_ai_cv_screening.php',
    'Section 43: Full End-to-End Recruitment Workflow' => __DIR__ . '/test_manual_e2e_workflow.php',
    'Unit & Integration Tests' => __DIR__ . '/test_hiring_workflow.php',
    'Refined End-to-End Hiring Simulation' => __DIR__ . '/test_e2e_hiring_workflow.php',
    'Security, Anti-IDOR & Edge Cases' => __DIR__ . '/test_security_and_edge_cases.php',
    'Company Verification & Access Control' => __DIR__ . '/test_company_verification.php'
];

$all_passed = true;
$summary = [];

foreach ($suites as $name => $path) {
    echo "Running Suite: $name...\n";
    $cmd = escapeshellcmd(PHP_BINARY) . " " . escapeshellarg($path);
    exec($cmd, $output, $return_code);
    
    if ($return_code === 0) {
        echo "  --> PASSED: $name\n\n";
        $summary[$name] = 'PASSED';
    } else {
        echo "  --> FAILED: $name (Exit Code $return_code)\n\n";
        echo implode("\n", array_slice($output, -15)) . "\n\n";
        $summary[$name] = 'FAILED';
        $all_passed = false;
    }
}

echo "======================================================================\n";
echo "                   FINAL TEST SUITE SUMMARY                          \n";
echo "======================================================================\n";
foreach ($summary as $suite => $status) {
    echo "  - $suite: $status\n";
}
echo "======================================================================\n";

if ($all_passed) {
    echo "CONGRATULATIONS: 100% OF TEST SUITES PASSED CLEANLY!\n";
    exit(0);
} else {
    echo "ATTENTION: ONE OR MORE TEST SUITES FAILED!\n";
    exit(1);
}
