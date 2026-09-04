<?php
$content = file_get_contents(__DIR__ . '/../public/assets/js/desktop/inventario.js');
$lines = explode("\n", $content);

echo "Analyzing JS syntax for inventario.js (" . count($lines) . " lines)..." . PHP_EOL;

$stack = []; // elements: ['char' => char, 'line' => line, 'col' => col, 'type' => 'brace'|'paren'|'bracket'|'template']
$modeStack = ['NORMAL']; // MODES: NORMAL, STRING, TEMPLATE_LITERAL, COMMENT, MULTI_COMMENT

$line = 1;
$col = 1;
$len = strlen($content);

for ($i = 0; $i < $len; $i++) {
    $c = $content[$i];
    if ($c === "\n") {
        $line++;
        $col = 1;
        if (end($modeStack) === 'COMMENT') {
            array_pop($modeStack);
        }
        continue;
    }
    $col++;

    $currentMode = end($modeStack);

    if ($currentMode === 'COMMENT') {
        continue;
    }

    if ($currentMode === 'MULTI_COMMENT') {
        if ($c === '*' && isset($content[$i+1]) && $content[$i+1] === '/') {
            array_pop($modeStack);
            $i++;
        }
        continue;
    }

    if ($currentMode === 'STRING') {
        if ($c === '\\') {
            $i++;
            continue;
        }
        if ($c === $strQuote) {
            array_pop($modeStack);
        }
        continue;
    }

    if ($currentMode === 'TEMPLATE_LITERAL') {
        if ($c === '\\') {
            $i++;
            continue;
        }
        if ($c === '`') {
            array_pop($modeStack);
            continue;
        }
        if ($c === '$' && isset($content[$i+1]) && $content[$i+1] === '{') {
            $modeStack[] = 'NORMAL';
            $stack[] = ['char' => '${', 'line' => $line, 'col' => $col];
            $i++;
            continue;
        }
        continue;
    }

    // NORMAL mode
    if ($c === '/' && isset($content[$i+1]) && $content[$i+1] === '/') {
        $modeStack[] = 'COMMENT';
        $i++;
        continue;
    }
    if ($c === '/' && isset($content[$i+1]) && $content[$i+1] === '*') {
        $modeStack[] = 'MULTI_COMMENT';
        $i++;
        continue;
    }

    if ($c === '"' || $c === "'") {
        $modeStack[] = 'STRING';
        $strQuote = $c;
        continue;
    }

    if ($c === '`') {
        $modeStack[] = 'TEMPLATE_LITERAL';
        continue;
    }

    if ($c === '{' || $c === '(' || $c === '[') {
        $stack[] = ['char' => $c, 'line' => $line, 'col' => $col];
    } else if ($c === '}' || $c === ')' || $c === ']') {
        if (empty($stack)) {
            echo "SYNTAX ERROR: Extra '$c' at line $line, col $col" . PHP_EOL;
            echo "Context: " . trim($lines[$line - 1]) . PHP_EOL;
            break;
        }
        $top = end($stack);
        $match = false;
        if ($c === '}' && ($top['char'] === '{' || $top['char'] === '${')) $match = true;
        if ($c === ')' && $top['char'] === '(') $match = true;
        if ($c === ']' && $top['char'] === '[') $match = true;

        if ($match) {
            $popped = array_pop($stack);
            if ($popped['char'] === '${') {
                // Return to TEMPLATE_LITERAL mode
                if (end($modeStack) === 'NORMAL') {
                    array_pop($modeStack);
                }
            }
        } else {
            echo "SYNTAX ERROR: Expected match for '{$top['char']}' (from line {$top['line']}), but found '$c' at line $line, col $col" . PHP_EOL;
            echo "Line $line: " . trim($lines[$line - 1]) . PHP_EOL;
            break;
        }
    }
}

if (!empty($stack)) {
    $last = end($stack);
    echo "Unclosed item: '{$last['char']}' at line {$last['line']}, col {$last['col']}" . PHP_EOL;
    echo "Line {$last['line']}: " . trim($lines[$last['line'] - 1]) . PHP_EOL;
} else if ($i >= $len) {
    echo "ALL BRACKETS & TEMPLATES BALANCED CLEANLY!" . PHP_EOL;
}
