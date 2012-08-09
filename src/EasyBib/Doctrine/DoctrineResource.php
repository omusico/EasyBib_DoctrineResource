<?php
/**
 * Permission is hereby granted, free of charge, to any person obtaining
 * a copy of this software and associated documentation files (the
 * "Software"), to deal in the Software without restriction, including
 * without limitation the rights to use, copy, modify, merge, publish,
 * distribute, sublicense, and/or sell copies of the Software, and to
 * permit persons to whom the Software is furnished to do so, subject to
 * the following conditions:
 *
 * The above copyright notice and this permission notice shall be included
 * in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS
 * OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL
 * THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
 * DEALINGS IN THE SOFTWARE.
 *
 * PHP Version 5
 *
 * @category EasyBib
 * @package  DoctrineResource
 * @author   Michael Scholl <michael@sch0ll.de>
 * @license  http://www.opensource.org/licenses/mit-license.html MIT License
 * @version  git: $id$
 * @link     https://github.com/easybib/EasyBib_Form_Decorator
 */

/**
 * EasyBib_DoctrineResource
 *
 * Setup Doctrine EntityManager and add support for some Gedmo PlugIns
 * - provides model support
 * - provides buildBootstrapErrorDecorators method
 *   for adding css error classes to form if not valid
 *
 * @category EasyBib
 * @package  Form
 * @author   Michael Scholl <michael@sch0ll.de>
 * @license  http://www.opensource.org/licenses/mit-license.html MIT License
 * @version  Release: @package_version@
 * @link     https://github.com/easybib/EasyBib_Form_Decorator
 */

namespace EasyBib\Doctrine;

use Doctrine\ORM\EntityManager;
use Doctrine\Common\EventManager;
use Doctrine\Common\ClassLoader;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\ORM\Configuration;
use Doctrine\DBAL\Logging\EchoSQLLogger;
use Gedmo\Timestampable\TimestampableListener;
use Gedmo\Sluggable\SluggableListener;
use Gedmo\Tree\TreeListener;
//use DoctrineExtensions\Versionable\VersionListener;

class DoctrineResource
{
    /**
     * @var EntityManager
     */
    protected $em;

    /**
     * @var EventManager
     */
    protected $evm;

    /**
     * @var \Zend_Config_Ini
     */
    protected $config;

    /**
     * @var string
     */
    protected $rootPath;

    /**
     * @var string
     */
    protected $module;

    /**
     * @var string
     */
    protected $modulePath;

    /**
     * @var array
     */
    protected $options;

    /**
     * Setup Paths & Config
     *
     * @param object $config
     * @param string $rootPath
     * @param string $module
     * @param array  $options  (array with keys for timestampable,sluggable,tree)
     *
     * @return $this
     */
    public function __construct(\Zend_Config_Ini $config, $rootPath, $module, array $options)
    {
        if (!is_string($rootPath)) {
            throw new \InvalidArgumentException('RootPath needs to be given');
        }
        if (!is_string($module)) {
            throw new \InvalidArgumentException('Module name needs to be given');
        }
        $this->config     = $config;
        $this->rootPath   = $rootPath;
        $this->module     = $module;
        $this->modulePath = $rootPath . '/app/modules/' . $module;

        $this->setOptions($options);

        $this->init();
    }

    /**
     * Set options and validate input a little.
     *
     * @param array $options
     *
     * @return $this
     */
    public function setOptions(array $options)
    {
        $defaultOptions = array(
            'timestampable' => false,
            'sluggable'     => false,
            'tree'          => false,
            'profile'       => false
        );

        if (count($options) > 0) {
            foreach ($options as $option => $value) {
                if (false === array_key_exists($option, $defaultOptions)) {
                    throw new \InvalidArgumentException("We currently do not support: {$option}.");
                }
                if (false === is_bool($value)) {
                    throw new \InvalidArgumentException("Value for '{$option}' must be 'true' or 'false'.");
                }
                $defaultOptions[$option] = $value;
            }
        }

        $this->options = $defaultOptions;

        return $this;
    }

    /**
     * Setup Doctrine Class Loaders & EntityManager
     *
     * return void
     */
    protected function init()
    {
        $this->evm = new EventManager();
        $config    = new Configuration();

        // timestampable
        if (!empty($this->options['timestampable'])) {
            $this->addTimestampable();
        }
        // sluggable
        if (!empty($this->options['sluggable'])) {
            $this->addSluggable();
        }
        // tree
        if (!empty($this->options['tree'])) {
            $this->addTree();
        }
        // profile logger
        if (!empty($this->options['profile'])) {
            $config->setSQLLogger(new EchoSQLLogger());
        }

        $cache        = new $this->config->cacheImplementation();
        $modelFolders = $this->getEntityFolders();
        $proxyFolder  = $this->getProxyFolder();
        $driverImpl   = $config->newDefaultAnnotationDriver($modelFolders);

        $annotationReader = new \Doctrine\Common\Annotations\AnnotationReader;
        $cachedAnnotationReader = new \Doctrine\Common\Annotations\CachedReader(
            $annotationReader, // use reader
            $cache // and a cache driver
        );

        $annotationDriver = new \Doctrine\ORM\Mapping\Driver\AnnotationDriver(
            $cachedAnnotationReader, // our cached annotation reader
            array($modelFolders) // paths to look in
        );

        $this->registerAutoloadNamespaces();

        $config->setMetadataDriverImpl($annotationDriver);
        $config->setMetadataCacheImpl($cache);
        $config->setQueryCacheImpl($cache);
        $config->setProxyDir($proxyFolder);
        $config->setProxyNamespace($this->config->proxy->namespace);
        $config->setAutoGenerateProxyClasses($this->config->autoGenerateProxyClasses);

        $this->em = EntityManager::create(
            $this->config->connection->toArray(),
            $config,
            $this->evm
        );
        \Zend_Registry::set('em', $this->em);
        return;
    }

    /**
     * Add Timestampable listener
     *
     * @return void
     */
    protected function addTimestampable()
    {
        if (!empty($this->evm)) {
            $this->evm->addEventSubscriber(new TimestampableListener());
        }
    }

    /**
     * Add Sluggable listener
     *
     * @return void
     */
    protected function addSluggable()
    {
        if (!empty($this->evm)) {
            $this->evm->addEventSubscriber(new SluggableListener());
        }
    }

    /**
     * Add Tree listener
     *
     * @return void
     */
    protected function addTree()
    {
        if (!empty($this->evm)) {
            $this->evm->addEventSubscriber(new TreeListener());
        }
    }

    /**
     * Get Entity folders
     *
     * @return array
     */
    protected function getEntityFolders()
    {
        $folders = array(
            $this->rootPath . '/library/Doctrine/Model'
        );

        if (is_dir($this->modulePath . '/' . $this->config->modelFolder)) {
            $folders[] = $this->modulePath . '/' . $this->config->modelFolder;
        }

        return $folders;
    }

    /**
     * Get Proxy folders
     *
     * @return string
     */
    protected function getProxyFolder()
    {
        /*$folders = array(
            $this->rootPath . '/library/Doctrine/Proxy'
        );
        if (is_dir($this->modulePath . '/' . $this->config->proxy->folder)) {
            $folders[] = $this->modulePath . '/' . $this->config->proxy->folder;
        }*/
        return $this->rootPath . '/library/Doctrine/Proxy';
    }

    /**
     * Register Autoload Namespaces
     *
     * @return void
     */
    protected function registerAutoloadNamespaces()
    {
        AnnotationRegistry::registerAutoloadNamespace(
            'Gedmo\Mapping\Annotation',
            $this->rootPath . '/vendor/gedmo/doctrine-extensions/lib'
        );
    }

    /**
     * Get the Doctrine EntityManager
     *
     * @return Doctrine\ORM\EntityManager
     */
    public function getEntityManager()
    {
        return $this->em;
    }
}
