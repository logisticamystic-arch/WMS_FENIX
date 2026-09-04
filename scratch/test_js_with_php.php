<?php
$code = file_get_contents(__DIR__ . '/../public/assets/js/desktop/inventario.js');
$lines = explode("\n", $code);

$braces = 0; $parens = 0; $brackets = 0;

foreach ($lines as $idx => $lineContent) {
    $lineNum = $idx + 1;
    // Strip single line comments and strings roughly for structure check
    $lineClean = preg_replace('/\/\/.*$/', '', $lineContent);
    
    // Count char by char
    for ($i = 0; $i < strlen($lineClean); $i++) {
        $ch = $lineClean[$i];
        if ($ch === '{') $braces++;
        if ($ch === '}') $braces--;
        if ($ch === '(') $parens++;
        if ($ch === ')') $parens--;
        if ($ch === '[') $brackets++;
        if ($ch === ']') $brackets--;

        if ($braces < 0) {
            echo "BRACE NEGATIVE on line $lineNum: " . trim($lineContent) . PHP_EOL;
            $braces = 0;
        }
        if ($parens < 0) {
            echo "PAREN NEGATIVE on line $lineNum: " . trim($lineContent) . PHP_EOL;
            $parens = 0;
        }
        if ($brackets < 0) {
            echo "BRACKET NEGATIVE on line $lineNum: " . trim($lineContent) . PHP_EOL;
            $brackets = 0;
        }
    }
}

echo "End balance -> Braces: $braces, Parens: $parens, Brackets: $brackets" . PHP_EOL;
