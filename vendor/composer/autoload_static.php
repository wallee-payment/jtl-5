<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit8fbef0caddc6e50e6eda49c7d4adc4af
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
            $loader->prefixLengthsPsr4 = ComposerStaticInit8fbef0caddc6e50e6eda49c7d4adc4af::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit8fbef0caddc6e50e6eda49c7d4adc4af::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInit8fbef0caddc6e50e6eda49c7d4adc4af::$classMap;

        }, null, ClassLoader::class);
    }
}
