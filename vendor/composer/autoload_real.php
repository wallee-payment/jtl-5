<?php

// autoload_real.php @generated by Composer

class ComposerAutoloaderInit0c705d4f6ad44a60d6a6f8a5fdb0f3ef
{
    private static $loader;

    public static function loadClassLoader($class)
    {
        if ('Composer\Autoload\ClassLoader' === $class) {
            require __DIR__ . '/ClassLoader.php';
        }
    }

    /**
     * @return \Composer\Autoload\ClassLoader
     */
    public static function getLoader()
    {
        if (null !== self::$loader) {
            return self::$loader;
        }

        require __DIR__ . '/platform_check.php';

        spl_autoload_register(array('ComposerAutoloaderInit0c705d4f6ad44a60d6a6f8a5fdb0f3ef', 'loadClassLoader'), true, true);
        self::$loader = $loader = new \Composer\Autoload\ClassLoader(\dirname(__DIR__));
        spl_autoload_unregister(array('ComposerAutoloaderInit0c705d4f6ad44a60d6a6f8a5fdb0f3ef', 'loadClassLoader'));

        require __DIR__ . '/autoload_static.php';
        call_user_func(\Composer\Autoload\ComposerStaticInit0c705d4f6ad44a60d6a6f8a5fdb0f3ef::getInitializer($loader));

        $loader->register(true);

        return $loader;
    }
}
