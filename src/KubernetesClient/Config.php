<?php

namespace KubernetesClient;

use Flow\JSONPath\JSONPath;
use Flow\JSONPath\JSONPathException;
use Symfony\Component\Yaml\Yaml;

class Config
{
    /**
     * Provides a file name prefix to use for temporary files
     *
     * @var string
     */
    private static $temp_file_prefix = 'kubernetes-client-';

    /**
     * Keep track of temporary files created for cleanup
     *
     * @var array
     */
    private static $tempFiles = [];

    /**
     * Server URI (ie: https://host)
     *
     * @var string
     */
    private $server;

    /**
     * Struct of cluster from context
     *
     * @var array
     */
    private $cluster;

    /**
     * Struct of user from context
     *
     * @var array
     */
    private $user;

    /**
     * Struct of context
     *
     * @var array
     */
    private $context;

    /**
     * Active in-use context
     *
     * @var string
     */
    private $activeContextName;

    /**
     * Path to client PEM certificate
     *
     * @var string
     */
    private $clientCertificatePath ;

    /**
     * Path to client PEM key
     *
     * @var string
     */
    private $clientKeyPath;

    /**
     * Path to cluster CA PEM
     *
     * @var string
     */
    private $certificateAuthorityPath;

    /**
     * Authorization token
     *
     * @var string
     */
    private $token;

    /**
     * timestamp of token expiration
     *
     * @var int
     */
    private $expiry;

    /**
     * If the user token is generated via an auth provider such as gcp or azure
     *
     * @var bool
     */
    private $isAuthProvider = false;

    /**
     * Data from parsed config file
     *
     * @var array
     */
    private $parsedConfigFile;


    /**
     * Create a temporary file to be used and destroyed at shutdown
     *
     * @param $data
     * @return bool|string
     */
    private static function writeTempFile($data)
    {
        $file = tempnam(sys_get_temp_dir(), self::$temp_file_prefix);
        file_put_contents($file, $data);

        self::$tempFiles[] = $file;

        /*
        register_shutdown_function(function () use ($file) {
            if (file_exists($file)) {
                unlink($file);
            }
        });
        */

        return $file;
    }

    /**
     * Clean up temp files
     *
     * @param $path
     */
    private static function deleteTempFile($path)
    {
        if ((bool) $path && in_array($path, self::$tempFiles) && file_exists($path)) {
            unlink($path);
            self::$tempFiles = array_filter(self::$tempFiles, function ($e) use ($path) {
                return ($e !== $path);
            });
        }
    }

    /**
     * handle php shutdown
     */
    public static function shutdown()
    {
        foreach (self::$tempFiles as $tempFile) {
            self::deleteTempFile($tempFile);
        }
    }

    /**
     * Create a config based off running inside a cluster
     *
     * @return Config
     */
    public static function InClusterConfig()
    {
        $config = new Config();
        $config->setToken(file_get_contents('/var/run/secrets/kubernetes.io/serviceaccount/token'));
        $config->setCertificateAuthorityPath('/var/run/secrets/kubernetes.io/serviceaccount/ca.crt');
        $config->setServer('https://kubernetes.default.svc');

        return $config;
    }

    /**
     * Create a config from file will auto fallback to KUBECONFIG env variable or ~/.kube/config if no path supplied
     *
     * @param null $path
     * @param null $contextName
     * @return Config
     * @throws \Exception
     */
    public static function BuildConfigFromFile($path = null, $contextName = null)
    {
        if (empty($path)) {
            $path = getenv('KUBECONFIG');
        }

        if (empty($path)) {
            $path = getenv('HOME').'/.kube/config';
        }

        if (!file_exists($path)) {
            throw new \Exception('Config file does not exist: ' . $path);
        }

        if (function_exists('yaml_parse_file')) {
            $yaml = yaml_parse_file($path);
        } else {
            $yaml = Yaml::parseFile($path);
        }

        if (empty($contextName)) {
            $contextName = $yaml['current-context'];
        }

        $config = new Config();
        $config->setParsedConfigFile($yaml);
        $config->useContext($contextName);

        return $config;
    }

    /**
     * destruct
     */
    public function __destruct()
    {
        /**
         * @note these are only deleted if they were created as temp files
         */
        self::deleteTempFile($this->certificateAuthorityPath);
        self::deleteTempFile($this->clientCertificatePath);
        self::deleteTempFile($this->clientKeyPath);
    }

    /**
     * Switch contexts
     *
     * @param $contextName
     */
    public function useContext($contextName)
    {
        $this->resetAuthData();
        $this->setActiveContextName($contextName);
        $yaml = $this->getParsedConfigFile();
        $context = null;
        foreach ($yaml['contexts'] as $item) {
            if ($item['name'] == $contextName) {
                $context = $item['context'];
                break;
            }
        }

        $cluster = null;
        foreach ($yaml['clusters'] as $item) {
            if ($item['name'] == $context['cluster']) {
                $cluster = $item['cluster'];
                break;
            }
        }

        $user = null;
        foreach ($yaml['users'] as $item) {
            if ($item['name'] == $context['user']) {
                $user = $item['user'];
                break;
            }
        }

        $this->setContext($context);
        $this->setCluster($cluster);
        $this->setUser($user);
        $this->setServer($cluster['server']);

        if (!empty($cluster['certificate-authority-data'])) {
            $path = self::writeTempFile(base64_decode($cluster['certificate-authority-data'], true));
            $this->setCertificateAuthorityPath($path);
        }

        if (!empty($user['client-certificate-data'])) {
            $path = self::writeTempFile(base64_decode($user['client-certificate-data']));
            $this->setClientCertificatePath($path);
        }

        if (!empty($user['client-key-data'])) {
            $path = self::writeTempFile(base64_decode($user['client-key-data']));
            $this->setClientKeyPath($path);
        }

        if (!empty($user['token'])) {
            $this->setToken($user['token']);
        }

        if (!empty($user['auth-provider'])) {
            $this->setIsAuthProvider(true);
        }
    }

    /**
     * Reset relevant data when context switching
     */
    protected function resetAuthData()
    {
        $this->setCertificateAuthorityPath(null);
        $this->setClientCertificatePath(null);
        $this->setClientKeyPath(null);
        $this->setExpiry(null);
        $this->setToken(null);
        $this->setIsAuthProvider(false);
    }

    /**
     * Set server
     *
     * @param $server
     */
    public function setServer($server)
    {
        $this->server = $server;
    }

    /**
     * Get server
     *
     * @return string
     */
    public function getServer()
    {
        return $this->server;
    }

    /**
     * Set user
     *
     * @param $user array
     */
    public function setUser($user)
    {
        $this->user = $user;
    }

    /**
     * Get user
     *
     * @return array
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * Set context
     *
     * @param $context array
     */
    public function setContext($context)
    {
        $this->context = $context;
    }

    /**
     * Set context
     *
     * @return array
     */
    public function getContext()
    {
        return $this->context;
    }

    /**
     * Set cluster
     *
     * @param $cluster array
     */
    public function setCluster($cluster)
    {
        $this->cluster = $cluster;
    }

    /**
     * Get cluster
     *
     * @return array
     */
    public function getCluster()
    {
        return $this->cluster;
    }

    /**
     * Set activeContextName
     *
     * @param $name string
     */
    protected function setActiveContextName($name)
    {
        $this->activeContextName = $name;
    }

    /**
     * Get activeContextName
     *
     * @return string
     */
    public function getActiveContextName()
    {
        return $this->activeContextName;
    }

    /**
     * Set token
     *
     * @param $token
     */
    public function setToken($token)
    {
        $this->token = $token;
    }

    /**
     * Get token
     *
     * @throws JSONPathException
     * @return string
     */
    public function getToken()
    {
        if ($this->getIsAuthProvider()) {
            // set token if expired
            if ($this->getExpiry() && time() >= $this->getExpiry()) {
                $this->getAuthProviderToken();
            }

            // set token if we do not have one yet
            if (empty($this->token)) {
                $this->getAuthProviderToken();
            }
        }

        return $this->token;
    }

    /**
     * @link https://github.com/kubernetes-client/javascript/blob/master/src/cloud_auth.ts - Official JS Implementation
     *
     * Set the token and expiry when using an auth provider
     *
     * @throws JSONPathException
     */
    protected function getAuthProviderToken()
    {
        $user = $this->getUser();

        // gcp, azure, etc
        //$name = (new JSONPath($user))->find('$.auth-provider.name')[0];

        // build command
        $cmd_path = (new JSONPath($user))->find('$.auth-provider.config.cmd-path')[0];
        $cmd_args = (new JSONPath($user))->find('$.auth-provider.config.cmd-args')[0];
        $command = "${cmd_path} ${cmd_args}";

        // execute command and store output
        $output = [];
        $exit_code = null;
        exec($command, $output, $exit_code);
        $output = implode("\n", $output);

        if ($exit_code !== 0) {
            throw new \Error("error executing access token command \"${command}\": ${output}");
        } else {
            $output = json_decode($output, true);
        }

        if (!is_array($output)) {
            throw new \Error("error retrieving token: auth provider failed to return valid data");
        }

        $expiry_key = (new JSONPath($user))->find('$.auth-provider.config.expiry-key')[0];
        $token_key = (new JSONPath($user))->find('$.auth-provider.config.token-key')[0];

        if ($expiry_key) {
            $expiry_key = '$' . trim($expiry_key, "{}");
            $this->setExpiry((new JSONPath($user))->find($expiry_key)[0]);
        }

        if ($token_key) {
            $token_key = '$' . trim($token_key, "{}");
            $this->setToken((new JSONPath($user))->find($token_key)[0]);
        }
    }

    /**
     * Set expiry
     *
     * @param $expiry
     */
    public function setExpiry($expiry)
    {
        if (!empty($expiry) && !is_int($expiry)) {
            $expiry = strtotime($expiry);
        }
        $this->expiry = $expiry;
    }

    /**
     * Get expiry
     *
     * @return int
     */
    public function getExpiry()
    {
        return $this->expiry;
    }

    /**
     * Set client certificate path
     *
     * @param $path
     */
    public function setClientCertificatePath($path)
    {
        self::deleteTempFile($this->clientCertificatePath);
        $this->clientCertificatePath = $path;
    }

    /**
     * Get client certificate path
     *
     * @return string
     */
    public function getClientCertificatePath()
    {
        return $this->clientCertificatePath;
    }

    /**
     * Set client key path
     *
     * @param $path
     */
    public function setClientKeyPath($path)
    {
        self::deleteTempFile($this->clientKeyPath);
        $this->clientKeyPath = $path;
    }

    /**
     * Get client key path
     *
     * @return string
     */
    public function getClientKeyPath()
    {
        return $this->clientKeyPath;
    }

    /**
     * Set cluster CA path
     *
     * @param $path
     */
    public function setCertificateAuthorityPath($path)
    {
        self::deleteTempFile($this->certificateAuthorityPath);
        $this->certificateAuthorityPath = $path;
    }

    /**
     * Get cluster CA path
     *
     * @return string
     */
    public function getCertificateAuthorityPath()
    {
        return $this->certificateAuthorityPath;
    }

    /**
     * Set if user credentials use auth provider
     *
     * @param $v bool
     */
    public function setIsAuthProvider($v)
    {
        $this->isAuthProvider = $v;
    }

    /**
     * Get if user credentials use auth provider
     *
     * @return bool
     */
    public function getIsAuthProvider()
    {
        return $this->isAuthProvider;
    }

    /**
     * Set the data of the parsed config file
     *
     * @param $data array
     */
    public function setParsedConfigFile($data)
    {
        $this->parsedConfigFile = $data;
    }

    /**
     * Get the data of the parsed config file
     *
     * @return array
     */
    public function getParsedConfigFile()
    {
        return $this->parsedConfigFile;
    }
}

register_shutdown_function(array('KubernetesClient\Config', 'shutdown'));
