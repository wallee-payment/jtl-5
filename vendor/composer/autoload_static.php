<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit56b68c595ff267fa2a19c61e857f47bd
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
            $loader->prefixLengthsPsr4 = ComposerStaticInit56b68c595ff267fa2a19c61e857f47bd::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit56b68c595ff267fa2a19c61e857f47bd::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInit56b68c595ff267fa2a19c61e857f47bd::$classMap;

        }, null, ClassLoader::class);
    }
}
