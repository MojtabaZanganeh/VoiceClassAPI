<?php

use Classes\Base\Error;

require_once 'Classes/Base/class-error.php';

/**
 * Autoloads classes dynamically based on their namespace and class name.
 * The function follows a specific directory structure to load class files
 * when they are referenced, without the need to explicitly include them.
 * 
 * It processes the class name, converts it to lowercase, and searches for the
 * corresponding PHP file in the directory structure. The expected structure
 * is: BASE_DIR/namespace/class-name.php.
 * 
 * @param string $className The fully qualified name of the class to load
 * 
 * @return void
 */
function classAutoloader($className)
{
    if (strpos($className, 'Classes\\') !== 0) {
        return;
    }
    $className = trim($className, '\\');
    $classNameArray = explode('\\', $className);
    $baseDir = __DIR__ . DIRECTORY_SEPARATOR . $classNameArray[0] . DIRECTORY_SEPARATOR . $classNameArray[1] . DIRECTORY_SEPARATOR;
    $className = isset($classNameArray[2]) ? $classNameArray[2] : $classNameArray[1];
    $className = strtolower(str_replace('_', "-", $className));

    $filePath = $baseDir . 'class-' . $className . '.php';

    if (file_exists($filePath)) {
        include_once $filePath;
    }

}

spl_autoload_register("classAutoloader");