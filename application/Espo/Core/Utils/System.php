<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2021 Yurii Kuznietsov, Taras Machyshyn, Oleksii Avramenko
 * Website: https://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Core\Utils;

use Symfony\Component\Process\PhpExecutableFinder;

class System
{
    /**
     * Get web server name
     *
     * @return string Ex. "microsoft-iis", "nginx", "apache"
     */
    public function getServerType()
    {
        $serverSoft = $_SERVER['SERVER_SOFTWARE'];

        preg_match('/^(.*?)\//i', $serverSoft, $match);
        if (empty($match[1])) {
            preg_match('/^(.*)\/?/i', $serverSoft, $match);
        }
        $serverName = strtolower( trim($match[1]) );

        return $serverName;
    }

    /**
     * Get Operating System of web server. Details http://en.wikipedia.org/wiki/Uname
     *
     * @return string  Ex. "windows", "mac", "linux"
     */
    public function getOS()
    {
        $osList = array(
            'windows' => array(
                'win',
                'UWIN',
            ),
            'mac' => array(
                'mac',
                'darwin',
            ),
            'linux' => array(
                'linux',
                'cygwin',
                'GNU',
                'FreeBSD',
                'OpenBSD',
                'NetBSD',
            ),
        );

        $sysOS = strtolower(PHP_OS);

        foreach ($osList as $osName => $osSystem) {
            if (preg_match('/^('.implode('|', $osSystem).')/i', $sysOS)) {
                return $osName;
            }
        }

        return false;
    }

    /**
     * Get root directory of EspoCRM
     *
     * @return string
     */
    public function getRootDir()
    {
        $bPath = realpath('bootstrap.php');
        $rootDir = dirname($bPath);

        return $rootDir;
    }

    /**
     * Deprecated. Use getPhpBinary()
     */
    public function getPhpBin()
    {
        return $this->getPhpBinary();
    }

    /**
     * Get PHP binary
     *
     * @return string
     */
    public function getPhpBinary()
    {
        return (new PhpExecutableFinder)->find();
    }

    /**
     * Get php version (only digits and dots)
     *
     * @return string
     */
    public static function getPhpVersion()
    {
        $version = phpversion();

        if (preg_match('/^[0-9\.]+[0-9]/', $version, $matches)) {
            return $matches[0];
        }

        return $version;
    }

    public function getPhpParam($name)
    {
        return ini_get($name);
    }

    public function hasPhpLib($name)
    {
        return extension_loaded($name);
    }

    public static function getPid()
    {
        if (function_exists('getmypid')) {
            return getmypid();
        }
    }

    public static function isProcessActive($pid)
    {
        if (empty($pid)) return false;

        if (!self::isPosixSupported()) return false;

        if (posix_getsid($pid) === false) return false;

        return true;
    }

    public static function isPosixSupported()
    {
        return function_exists('posix_getsid');
    }
}
