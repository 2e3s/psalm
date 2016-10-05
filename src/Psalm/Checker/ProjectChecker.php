<?php

namespace Psalm\Checker;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use Psalm\Config;
use Psalm\IssueBuffer;
use Psalm\Exception;

class ProjectChecker
{
    /**
     * Cached config
     * @var Config|null
     */
    protected static $config;

    /**
     * Whether or not to use colors in error output
     * @var boolean
     */
    public static $use_color = true;

    /**
     * Whether or not to show informational messages
     * @var boolean
     */
    public static $show_info = true;

    public static function check($debug = false, array $diff_files = [])
    {
        if (!self::$config) {
            self::$config = self::getConfigForPath(getcwd());
        }

        $file_list = [];

        if ($diff_files) {
            FileChecker::loadReferenceCache();
            $file_list = self::getReferencedFilesFromDiff($diff_files);
            self::checkDiffFilesWithConfig($file_list, self::$config, $debug);
        }
        else {
            foreach (self::$config->getIncludeDirs() as $dir_name) {
                self::checkDirWithConfig($dir_name, self::$config, $debug, $file_list);
            }
        }

        IssueBuffer::finish();
    }

    public static function checkDir($dir_name, $debug = false)
    {
        if (!self::$config) {
            self::$config = self::getConfigForPath($dir_name);
            self::$config->hide_external_errors = self::$config->isInProjectDirs(self::$config->shortenFileName($dir_name));
        }

        FileChecker::loadReferenceCache();

        self::checkDirWithConfig($dir_name, self::$config, $debug);

        IssueBuffer::finish();
    }

    protected static function checkDirWithConfig($dir_name, Config $config, $debug, array $file_list = [])
    {
        $file_extensions = $config->getFileExtensions();
        $filetype_handlers = $config->getFiletypeHandlers();

        /** @var RecursiveDirectoryIterator */
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir_name));
        $iterator->rewind();

        $files = [];

        while ($iterator->valid()) {
            if (!$iterator->isDot()) {
                $extension = $iterator->getExtension();
                if (in_array($extension, $file_extensions)) {
                    $file_name = $iterator->getRealPath();

                    if ($debug) {
                        echo 'Checking ' . $file_name . PHP_EOL;
                    }

                    if ($file_list) {
                        if (in_array($file_name, $file_list)) {
                            echo 'Checking affected file ' . $file_name . PHP_EOL;
                        }
                        else {
                            continue;
                        }
                    }

                    if (isset($filetype_handlers[$extension])) {
                        /** @var FileChecker */
                        $file_checker = new $filetype_handlers[$extension]($file_name);
                    }
                    else {
                        $file_checker = new FileChecker($file_name);
                    }

                    $file_checker->check(true);
                }
            }

            $iterator->next();
        }
    }

    protected static function checkDiffFilesWithConfig(array $file_list = [], Config $config, $debug)
    {
        $file_extensions = $config->getFileExtensions();
        $filetype_handlers = $config->getFiletypeHandlers();

        foreach ($file_list as $file_name) {
            $extension = pathinfo($file_name, PATHINFO_EXTENSION);

            if ($debug) {
                echo 'Checking affected file ' . $file_name . PHP_EOL;
            }

            if (isset($filetype_handlers[$extension])) {
                /** @var FileChecker */
                $file_checker = new $filetype_handlers[$extension]($file_name);
            }
            else {
                $file_checker = new FileChecker($file_name);
            }

            $file_checker->check(true);
        }
    }

    public static function checkFile($file_name, $debug = false)
    {
        if ($debug) {
            echo 'Checking ' . $file_name . PHP_EOL;
        }

        if (!self::$config) {
            self::$config = self::getConfigForPath($file_name);
        }

        self::$config->hide_external_errors = self::$config->isInProjectDirs(self::$config->shortenFileName($file_name));

        $file_name_parts = explode('.', $file_name);

        $extension = array_pop($file_name_parts);

        $filetype_handlers = self::$config->getFiletypeHandlers();

        FileChecker::loadReferenceCache();

        if (isset($filetype_handlers[$extension])) {
            /** @var FileChecker */
            $file_checker = new $filetype_handlers[$extension]($file_name);
        }
        else {
            $file_checker = new FileChecker($file_name);
        }

        $file_checker->check(true);

        IssueBuffer::finish();
    }

    /**
     * Gets a Config object from an XML file.
     * Searches up a folder hierachy for the most immediate config.
     *
     * @param  string $path
     * @return Config
     */
    protected static function getConfigForPath($path)
    {
        $dir_path = realpath($path) . '/';

        if (!is_dir($dir_path)) {
            $dir_path = dirname($dir_path) . '/';
        }

        $config = null;

        do {
            $maybe_path = $dir_path . Config::DEFAULT_FILE_NAME;

            if (file_exists($maybe_path)) {
                $config = \Psalm\Config::loadFromXML($maybe_path);

                if ($config->autoloader) {
                    require_once($dir_path . $config->autoloader);
                }

                break;
            }

            $dir_path = preg_replace('/[^\/]+\/$/', '', $dir_path);
        }
        while ($dir_path !== '/');

        if (!$config) {
            throw new Exception\ConfigException('Config not found for path ' . $path);
        }

        return $config;
    }

    public static function setConfigXML($path_to_config)
    {
        if (!file_exists($path_to_config)) {
            throw new Exception\ConfigException('Config not found at location ' . $path_to_config);
        }

        $dir_path = dirname($path_to_config) . '/';

        self::$config = \Psalm\Config::loadFromXML($path_to_config);

        if (self::$config->autoloader) {
            require_once($dir_path . self::$config->autoloader);
        }
    }

    public static function getReferencedFilesFromDiff(array $diff_files)
    {
        $all_files_to_check = $diff_files;

        while ($diff_files) {
            $diff_file = array_shift($diff_files);
            $dependent_files = FileChecker::getFilesReferencingFile($diff_file);
            //var_dump($dependent_files);
            $new_files = array_diff($dependent_files, $all_files_to_check);

            $all_files_to_check += $new_files;
            $diff_files += $new_files;
        }

        return $all_files_to_check;
    }
}
