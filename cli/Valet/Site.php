<?php

namespace Valet;

class Site
{
    public $config;
    public $cli;
    public $files;

    /**
     * Create a new Site instance.
     *
     * @param Configuration $config
     * @param CommandLine   $cli
     * @param Filesystem    $files
     */
    public function __construct(Configuration $config, CommandLine $cli, Filesystem $files)
    {
        $this->cli = $cli;
        $this->files = $files;
        $this->config = $config;
    }

    /**
     * Get the real hostname for the given path, checking links.
     *
     * @param string $path
     *
     * @return string|null
     */
    public function host($path)
    {
        foreach ($this->files->scandir($this->sitesPath()) as $link) {
            if ($resolved = realpath($this->sitesPath().'/'.$link) == $path) {
                return $link;
            }
        }

        return basename($path);
    }

    /**
     * Link the current working directory with the given name.
     *
     * @param string $target
     * @param string $link
     *
     * @return string
     */
    public function link($target, $link)
    {
        $this->files->ensureDirExists(
            $linkPath = $this->sitesPath(), user()
        );

        $this->config->prependPath($linkPath);

        $this->files->symlinkAsUser($target, $linkPath.'/'.$link);

        return $linkPath.'/'.$link;
    }

    /**
     * Unlink the given symbolic link.
     *
     * @param string $name
     *
     * @return void
     */
    public function unlink($name)
    {
        if ($this->files->exists($path = $this->sitesPath().'/'.$name)) {
            $this->files->unlink($path);
        }
    }

    /**
     * Remove all broken symbolic links.
     *
     * @return void
     */
    public function pruneLinks()
    {
        $this->files->ensureDirExists($this->sitesPath(), user());

        $this->files->removeBrokenLinksAt($this->sitesPath());
    }

    /**
     * Resecure all currently secured sites with a fresh domain.
     *
     * @param string $oldDomain
     * @param string $domain
     *
     * @return void
     */
    public function resecureForNewDomain($oldDomain, $domain)
    {
        if (!$this->files->exists($this->certificatesPath())) {
            return;
        }

        $secured = $this->secured();

        foreach ($secured as $url) {
            $this->unsecure($url);
        }

        foreach ($secured as $url) {
            $this->secure(str_replace('.'.$oldDomain, '.'.$domain, $url));
        }
    }

    /**
     * Get all of the URLs that are currently secured.
     *
     * @return array
     */
    public function secured()
    {
        return collect($this->files->scandir($this->certificatesPath()))
                    ->map(function ($file) {
                        return str_replace(['.key', '.csr', '.crt'], '', $file);
                    })->unique()->values()->all();
    }

    /**
     * Secure the given host with TLS.
     *
     * @param string $url
     *
     * @return void
     */
    public function secure($url)
    {
        $this->unsecure($url);

        $this->files->ensureDirExists($this->certificatesPath(), user());

        $this->createCertificate($url);

        $this->files->putAsUser(
            VALET_HOME_PATH.'/Caddy/'.$url, $this->buildSecureCaddyfile($url)
        );
    }

    /**
     * Create and trust a certificate for the given URL.
     *
     * @param string $url
     *
     * @return void
     */
    public function createCertificate($url)
    {
        $keyPath = $this->certificatesPath().'/'.$url.'.key';
        $csrPath = $this->certificatesPath().'/'.$url.'.csr';
        $crtPath = $this->certificatesPath().'/'.$url.'.crt';

        $this->createPrivateKey($keyPath);
        $this->createSigningRequest($url, $keyPath, $csrPath);

        $this->cli->runAsUser(sprintf(
            'openssl x509 -req -days 365 -in %s -signkey %s -out %s', $csrPath, $keyPath, $crtPath
        ));

        $this->trustCertificate($crtPath);
    }

    /**
     * Create the private key for the TLS certificate.
     *
     * @param string $keyPath
     *
     * @return void
     */
    public function createPrivateKey($keyPath)
    {
        $this->cli->runAsUser(sprintf('openssl genrsa -out %s 2048', $keyPath));
    }

    /**
     * Create the signing request for the TLS certificate.
     *
     * @param string $keyPath
     *
     * @return void
     */
    public function createSigningRequest($url, $keyPath, $csrPath)
    {
        $this->cli->runAsUser(sprintf(
            'openssl req -new -subj "/C=/ST=/O=/localityName=/commonName=%s/organizationalUnitName=/emailAddress=/" -key %s -out %s -passin pass:',
            $url, $keyPath, $csrPath
        ));
    }

    /**
     * Trust the given certificate file in the Mac Keychain.
     *
     * @param string $crtPath
     *
     * @return void
     */
    public function trustCertificate($crtPath)
    {
        $this->cli->run(sprintf(
            'sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain %s', $crtPath
        ));
    }

    /**
     * Build the TLS secured Caddyfile for the given URL.
     *
     * @param string $url
     *
     * @return string
     */
    public function buildSecureCaddyfile($url)
    {
        $path = $this->certificatesPath();

        return str_replace(
            ['VALET_SITE', 'VALET_CERT', 'VALET_KEY', 'FPM_ADDRESS'],
            [$url, $path.'/'.$url.'.crt', $path.'/'.$url.'.key', get_config('systemd-caddy-fpm')],
            $this->files->get(__DIR__.'/../stubs/SecureCaddyfile')
        );
    }

    /**
     * Unsecure the given URL so that it will use HTTP again.
     *
     * @param string $url
     *
     * @return void
     */
    public function unsecure($url)
    {
        if ($this->files->exists($this->certificatesPath().'/'.$url.'.crt')) {
            $this->files->unlink(VALET_HOME_PATH.'/Caddy/'.$url);

            $this->files->unlink($this->certificatesPath().'/'.$url.'.key');
            $this->files->unlink($this->certificatesPath().'/'.$url.'.csr');
            $this->files->unlink($this->certificatesPath().'/'.$url.'.crt');

            $this->cli->run(sprintf('sudo security delete-certificate -c "%s" -t', $url));
        }
    }

    /**
     * Get all of the log files for all sites.
     *
     * @param array $paths
     *
     * @return array
     */
    public function logs($paths)
    {
        $files = collect();

        foreach ($paths as $path) {
            $files = $files->merge(collect($this->files->scandir($path))->map(function ($directory) use ($path) {
                $logPath = $path.'/'.$directory.'/storage/logs/laravel.log';

                if ($this->files->isDir(dirname($logPath))) {
                    return $this->files->touchAsUser($logPath);
                }
            })->filter());
        }

        return $files->values()->all();
    }

    /**
     * Get the path to the linked Valet sites.
     *
     * @return string
     */
    public function sitesPath()
    {
        return VALET_HOME_PATH.'/Sites';
    }

    /**
     * Get the path to the Valet TLS certificates.
     *
     * @return string
     */
    public function certificatesPath()
    {
        return VALET_HOME_PATH.'/Certificates';
    }
}
