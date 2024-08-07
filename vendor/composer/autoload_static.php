<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInitdd4a0565a9f7a4c8d9f67a505cd5c497
{
    public static $prefixLengthsPsr4 = array (
        'W' => 
        array (
            'Wallee\\Sdk\\' => 11,
            'WalleePayment\\' => 14,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Wallee\\Sdk\\' => 
        array (
            0 => __DIR__ . '/..' . '/wallee/sdk/lib',
        ),
        'WalleePayment\\' => 
        array (
            0 => __DIR__ . '/../..' . '/Wallee',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInitdd4a0565a9f7a4c8d9f67a505cd5c497::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInitdd4a0565a9f7a4c8d9f67a505cd5c497::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInitdd4a0565a9f7a4c8d9f67a505cd5c497::$classMap;

        }, null, ClassLoader::class);
    }
}
