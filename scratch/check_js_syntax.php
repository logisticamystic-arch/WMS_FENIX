<?php
$file = __DIR__ . '/../public/assets/js/desktop/inventario.js';
$lines = file($file);
echo "Total lineas: " . count($lines) . PHP_EOL;

$content = file_get_contents($file);

// Check backticks, braces, parentheses count line by line and in blocks
$stack = [];
$inString = false;
$stringChar = null;
$inTemplate = 0;

for ($i = 0; $i < strlen($content); $i++) {
    $char = $content[$i];
    $lineNum = substr_count(substr($content, 0, $i), "\n") + 1;
    
    // Check template literal opening/closing
    if ($char === '`' && ($i === 0 || $content[$i-1] !== '\\')) {
        // toggle template literal
    }
}

// Let's inspect lines 5130 to 5190 directly:
echo "--- LINEAS 5130 A 5190 ---" . PHP_EOL;
for ($l = 5130; $l <= 5190; $l++) {
    if (isset($lines[$l - 1])) {
        echo sprintf("%4d: %s", $l, $lines[$l - 1]);
    }
}
