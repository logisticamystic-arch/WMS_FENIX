<?php
// Script automatizado para verificar la sintaxis de todos los archivos PHP.
// Recomendado ejecutar antes de confirmaciones o después de que la IA realice cambios.
// Uso: php tools/verify_syntax.php

$basePath = realpath(__DIR__ . '/../');
$foldersToCheck = ['src', 'public', 'config', 'bootstrap.php'];
$errors = 0;

echo "=============================================\n";
echo " Verificacion de Linter PHP - WMS Prooriente \n";
echo "=============================================\n\n";

// Procesar tanto directorios como archivos individuales
foreach ($foldersToCheck as $item) {
    $path = $basePath . DIRECTORY_SEPARATOR . $item;
    
    // Si es un archivo directo
    if (is_file($path) && pathinfo($path, PATHINFO_EXTENSION) === 'php') {
        checkFileSyntax($path, $basePath, $errors);
        continue;
    }
    
    // Si es un directorio
    if (!is_dir($path)) continue;

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            checkFileSyntax($file->getPathname(), $basePath, $errors);
        }
    }
}

function checkFileSyntax($filePath, $basePath, &$errors) {
    $code = 0;
    $output = [];
    exec('php -l ' . escapeshellarg($filePath) . ' 2>&1', $output, $code);
    
    if ($code !== 0) {
        echo "[\033[31mERROR\033[0m] " . str_replace($basePath, '', $filePath) . "\n";
        echo implode("\n", $output) . "\n\n";
        $errors++;
    }
}

echo "\n";
if ($errors === 0) {
    echo "[\033[32mOK\033[0m] Sintaxis Impecable! Ningun error detectado.\n";
    exit(0);
} else {
    echo "[\033[31mFALLO\033[0m] Operacion cancelada. Se detectaron $errors error(es) de sintaxis.\n";
    exit(1);
}
