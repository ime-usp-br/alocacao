<?php
/**
 * Script to validate test coverage meets AC8 requirements (80% minimum)
 * 
 * Usage: php scripts/check-coverage.php [--min-coverage=80] [--show-details]
 */

$options = getopt('', ['min-coverage:', 'show-details', 'help']);

if (isset($options['help'])) {
    echo "Usage: php scripts/check-coverage.php [options]\n";
    echo "\nOptions:\n";
    echo "  --min-coverage=N   Minimum coverage percentage required (default: 80)\n";
    echo "  --show-details     Show detailed coverage information\n";
    echo "  --help            Show this help message\n";
    exit(0);
}

$minCoverage = isset($options['min-coverage']) ? (float)$options['min-coverage'] : 80.0;
$showDetails = isset($options['show-details']);

echo "🧪 AC8: Validating Test Coverage\n";
echo "================================\n";

// Check if coverage files exist
$coverageFiles = [
    'clover' => './coverage.xml',
    'text' => './coverage.txt'
];

$missingFiles = [];
foreach ($coverageFiles as $type => $file) {
    if (!file_exists($file)) {
        $missingFiles[] = $type . ' (' . $file . ')';
    }
}

if (!empty($missingFiles)) {
    echo "❌ Coverage files not found: " . implode(', ', $missingFiles) . "\n";
    echo "Run tests with coverage first: ./vendor/bin/phpunit --coverage-clover coverage.xml\n";
    exit(1);
}

// Parse coverage from clover XML
$coverage = parseCoverageFromClover($coverageFiles['clover']);

if ($coverage === null) {
    echo "❌ Failed to parse coverage data\n";
    exit(1);
}

echo "📊 Coverage Results:\n";
echo "==================\n";

$overallCoverage = $coverage['overall'];
echo sprintf("Overall Coverage: %.2f%%\n", $overallCoverage);

if ($showDetails) {
    echo "\n📁 Per-File Coverage:\n";
    foreach ($coverage['files'] as $file => $fileCoverage) {
        $status = $fileCoverage >= $minCoverage ? '✅' : '❌';
        echo sprintf("%s %s: %.2f%%\n", $status, $file, $fileCoverage);
    }

    echo "\n🎯 Focus Areas (AC8 Requirements):\n";
    echo "=================================\n";
    
    $focusAreas = [
        'ReservationApiService' => ['app/Services/ReservationApiService.php'],
        'ProcessReservation Job' => ['app/Jobs/ProcessReservation.php'],
        'MigrateReservationsToSalas Command' => ['app/Console/Commands/MigrateReservationsToSalas.php'],
        'SalasApiClient' => ['app/Services/SalasApiClient.php'],
        'ReservationMapper' => ['app/Services/ReservationMapper.php']
    ];

    foreach ($focusAreas as $area => $files) {
        $areaCoverage = calculateAreaCoverage($coverage['files'], $files);
        $status = $areaCoverage >= $minCoverage ? '✅' : '❌';
        echo sprintf("%s %s: %.2f%%\n", $status, $area, $areaCoverage);
    }
}

echo "\n🎯 Coverage Analysis:\n";
echo "====================\n";

if ($overallCoverage >= $minCoverage) {
    echo sprintf("✅ SUCCESS: Coverage %.2f%% meets minimum requirement of %.2f%%\n", 
                 $overallCoverage, $minCoverage);
    
    if ($overallCoverage >= 90) {
        echo "🏆 EXCELLENT: Coverage exceeds 90% - Outstanding quality!\n";
    } elseif ($overallCoverage >= 85) {
        echo "🌟 GREAT: Coverage exceeds 85% - Very good coverage!\n";
    }
} else {
    echo sprintf("❌ FAILURE: Coverage %.2f%% below minimum requirement of %.2f%%\n", 
                 $overallCoverage, $minCoverage);
    
    $gap = $minCoverage - $overallCoverage;
    echo sprintf("📈 Need to improve coverage by %.2f%% to meet AC8 requirements\n", $gap);
}

// Identify low-coverage files
$lowCoverageFiles = [];
foreach ($coverage['files'] as $file => $fileCoverage) {
    if ($fileCoverage < $minCoverage && $fileCoverage > 0) {
        $lowCoverageFiles[] = ['file' => $file, 'coverage' => $fileCoverage];
    }
}

if (!empty($lowCoverageFiles)) {
    echo "\n⚠️  Files needing attention (below {$minCoverage}%):\n";
    usort($lowCoverageFiles, function($a, $b) {
        return $a['coverage'] <=> $b['coverage'];
    });
    
    foreach ($lowCoverageFiles as $item) {
        echo sprintf("   📝 %s: %.2f%%\n", $item['file'], $item['coverage']);
    }
}

echo "\n📋 Test Suite Statistics:\n";
echo "========================\n";
echo sprintf("Total Files Analyzed: %d\n", count($coverage['files']));
echo sprintf("Files with Coverage: %d\n", count(array_filter($coverage['files'], function($c) { return $c > 0; })));
echo sprintf("Files Meeting Standard: %d\n", count(array_filter($coverage['files'], function($c) use ($minCoverage) { 
    return $c >= $minCoverage; 
})));

$recommendations = generateRecommendations($coverage, $minCoverage);
if (!empty($recommendations)) {
    echo "\n💡 Recommendations:\n";
    echo "==================\n";
    foreach ($recommendations as $recommendation) {
        echo "• $recommendation\n";
    }
}

// Exit with appropriate code
exit($overallCoverage >= $minCoverage ? 0 : 1);

/**
 * Parse coverage data from Clover XML file
 */
function parseCoverageFromClover($cloverFile) {
    if (!file_exists($cloverFile)) {
        return null;
    }

    $xml = simplexml_load_file($cloverFile);
    if ($xml === false) {
        return null;
    }

    $files = [];
    $totalElements = 0;
    $coveredElements = 0;

    foreach ($xml->xpath('//file') as $file) {
        $filename = (string)$file['name'];
        $filename = str_replace(getcwd() . '/', '', $filename);
        
        $metrics = $file->metrics;
        if ($metrics) {
            $elements = (int)$metrics['elements'];
            $covered = (int)$metrics['coveredelements'];
            
            $fileCoverage = $elements > 0 ? ($covered / $elements) * 100 : 0;
            $files[$filename] = $fileCoverage;
            
            $totalElements += $elements;
            $coveredElements += $covered;
        }
    }

    $overallCoverage = $totalElements > 0 ? ($coveredElements / $totalElements) * 100 : 0;

    return [
        'overall' => $overallCoverage,
        'files' => $files,
        'total_elements' => $totalElements,
        'covered_elements' => $coveredElements
    ];
}

/**
 * Calculate coverage for a specific area
 */
function calculateAreaCoverage($fileCoverages, $areaFiles) {
    $totalCoverage = 0;
    $fileCount = 0;
    
    foreach ($areaFiles as $file) {
        if (isset($fileCoverages[$file])) {
            $totalCoverage += $fileCoverages[$file];
            $fileCount++;
        }
    }
    
    return $fileCount > 0 ? $totalCoverage / $fileCount : 0;
}

/**
 * Generate improvement recommendations
 */
function generateRecommendations($coverage, $minCoverage) {
    $recommendations = [];
    
    if ($coverage['overall'] < $minCoverage) {
        $recommendations[] = sprintf(
            "Overall coverage %.2f%% is below target. Focus on adding tests for core functionality.",
            $coverage['overall']
        );
    }
    
    $uncoveredFiles = array_filter($coverage['files'], function($c) { return $c === 0; });
    if (count($uncoveredFiles) > 0) {
        $recommendations[] = sprintf(
            "%d files have no test coverage. Start with the most critical ones.",
            count($uncoveredFiles)
        );
    }
    
    $lowCoverageFiles = array_filter($coverage['files'], function($c) use ($minCoverage) {
        return $c > 0 && $c < $minCoverage;
    });
    if (count($lowCoverageFiles) > 0) {
        $recommendations[] = sprintf(
            "%d files have low coverage. Add tests for edge cases and error scenarios.",
            count($lowCoverageFiles)
        );
    }
    
    if ($coverage['overall'] >= $minCoverage) {
        $recommendations[] = "Coverage target met! Consider adding integration tests for end-to-end scenarios.";
        
        if ($coverage['overall'] < 90) {
            $recommendations[] = "Aim for 90%+ coverage by adding tests for exception paths and edge cases.";
        }
    }
    
    return $recommendations;
}