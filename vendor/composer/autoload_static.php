<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInitedc373967f94e5bf92545e4cb9c81cf7
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
            $loader->prefixLengthsPsr4 = ComposerStaticInitedc373967f94e5bf92545e4cb9c81cf7::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInitedc373967f94e5bf92545e4cb9c81cf7::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInitedc373967f94e5bf92545e4cb9c81cf7::$classMap;

        }, null, ClassLoader::class);
    }
}
