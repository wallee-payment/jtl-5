<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInitca30a8bfad359c5ad19714ec14e1256b
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
            $loader->prefixLengthsPsr4 = ComposerStaticInitca30a8bfad359c5ad19714ec14e1256b::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInitca30a8bfad359c5ad19714ec14e1256b::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInitca30a8bfad359c5ad19714ec14e1256b::$classMap;

        }, null, ClassLoader::class);
    }
}
