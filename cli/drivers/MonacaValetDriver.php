<?php

class MonacaValetDriver extends ValetDriver
{
    /**
     * Determine if the driver serves the request.
     *
     * @param  string $sitePath
     * @param  string $siteName
     * @param  string $uri
     * @return bool
     */
    public function serves($sitePath, $siteName, $uri)
    {
        // Some structure of your application matches the requested site so use this driver.
        return is_dir($sitePath . '/.monaca');
    }

    /**
     * Determine if the incoming request is for a static file.
     *
     * @param  string $sitePath
     * @param  string $siteName
     * @param  string $uri
     * @return string|false
     */
    public function isStaticFile($sitePath, $siteName, $uri)
    {
        // A non-PHP static file is found.
        if (file_exists($staticFilePath = $sitePath . '/www' . $uri) && !is_dir($staticFilePath) && pathinfo($staticFilePath)['extension'] != '.php') return $staticFilePath;
        return false;
    }

    /**
     * Get the fully resolved path to the application's front controller.
     *
     * @param  string $sitePath
     * @param  string $siteName
     * @param  string $uri
     * @return string
     */
    public function frontControllerPath($sitePath, $siteName, $uri)
    {
        return $sitePath.'/www/index.html';
    }
}

