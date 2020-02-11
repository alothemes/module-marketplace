<?php

namespace Swissup\Marketplace\Installer;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Filesystem\Directory\ReadFactory;
use Magento\Framework\Simplexml\Config;
use Magento\Framework\Simplexml\ConfigFactory;

class ConfigReader
{
    const DIR = 'etc/marketplace';

    const FILE = 'installer.xml';

    /**
     * @var string
     */
    protected $currentPath;

    /**
     * @var ComponentRegistrar
     */
    protected $componentRegistrar;

    /**
     * @var ReadFactory
     */
    protected $readDirFactory;

    /**
     * @var ConfigFactory
     */
    protected $configFactory;

    /**
     * @param ComponentRegistrar $componentRegistrar
     * @param ReadFactory $readDirFactory
     * @param ConfigFactory $configFactory
     */
    public function __construct(
        ComponentRegistrar $componentRegistrar,
        ReadFactory $readDirFactory,
        ConfigFactory $configFactory
    ) {
        $this->componentRegistrar = $componentRegistrar;
        $this->readDirFactory = $readDirFactory;
        $this->configFactory = $configFactory;
    }

    /**
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function read()
    {
        $output = [
            'rules' => [],      // package/name => [installer_keys]
            'fields' => [],     // installer_key => form data
            'conditions' => [], // installer_key => conditions to check before commands execution
            'commands' => [],   // installer_key => commands to run
        ];

        foreach ($this->readFiles() as $path => $content) {
            $this->currentPath = $path;

            $xml = $this->configFactory->create(['sourceData' => $content]);

            $packages = (array) $xml->getNode('packages/package');
            foreach ($packages as $packageName) {
                $output['rules'][$packageName][] = $path;
            }

            $output['fields'][$path] = $this->parseFields($xml);
            $output['conditions'][$path] = $this->parseConditions($xml);
            $output['commands'][$path] = $this->parseCommands($xml);
        }

        return $output;
    }

    /**
     * @return array
     */
    protected function readFiles()
    {
        $files = [];
        $paths = $this->componentRegistrar->getPaths(ComponentRegistrar::MODULE);
        $paths += $this->componentRegistrar->getPaths(ComponentRegistrar::THEME);

        foreach ($paths as $path) {
            $dir = $this->readDirFactory->create($path);
            $filepath = self::DIR . '/' . self::FILE;

            if (!$dir->isReadable($filepath)) {
                continue;
            }

            $files[$path] = $dir->readFile($filepath);
        }

        return $files;
    }

    /**
     * @param Config $xml
     * @return array
     */
    protected function parseFields(Config $xml)
    {
        $node = $xml->getNode('fields');
        if (!$node) {
            return [];
        }

        $result = [];
        foreach ($node->children() as $field) {
            $name = $field->getAttribute('name');

            if (!isset($result[$name])) {
                $result[$name] = [];
            }

            $result[$name]['title'] = $field->getAttribute('title');

            if (!$field->hasChildren()) {
                continue;
            }

            foreach ($field->children() as $option) {
                $value = (string) $option[0];
                $result[$name]['options'][$value] = [
                    'value' => $value,
                    'label' => $option->getAttribute('title'),
                ];
            }
        }

        return $result;
    }

    /**
     * @param Config $xml
     * @return array
     */
    protected function parseConditions(Config $xml)
    {
        $node = $xml->getNode('commands');
        if (!$node || !$node->descend('conditions')) {
            return [];
        }

        $result = [];
        foreach ($node->descend('conditions') as $condition) {
            $result = $this->parseArguments($condition);
        }

        return $result;
    }

    /**
     * @param Config $xml
     * @return array
     */
    protected function parseCommands(Config $xml)
    {
        $node = $xml->getNode('commands');
        if (!$node) {
            return [];
        }

        $commands = [];
        foreach ($node->children() as $child) {
            $tagName = $child->getName();

            if (!in_array($tagName, ['command', 'include'])) {
                continue;
            }

            if ($tagName === 'command') {
                $commands[] = $child;
                continue;
            }

            // read commands from separate file
            $path = $child->getAttribute('path');
            $dir = $this->readDirFactory->create($this->currentPath . '/' . self::DIR);

            if (!$dir->isReadable($path)) {
                continue;
            }

            $xml = $this->configFactory->create([
                'sourceData' => $dir->readFile($path)
            ]);

            foreach ($xml->getNode('command') as $command) {
                $commands[] = $command;
            }
        }

        $result = [];
        foreach ($commands as $i => $command) {
            $result[$i]['class'] = $command->getAttribute('class');

            if (!$command->hasChildren() || !$command->descend('data')) {
                continue;
            }

            $result[$i]['data'] = $this->parseArguments(
                $command->descend('data')->children()
            );
        }

        return $result;
    }

    /**
     * @param \Magento\Framework\Simplexml\Element $node
     * @return array
     */
    protected function parseArguments(\Magento\Framework\Simplexml\Element $node)
    {
        $i = 0;
        $result = [];

        foreach ($node as $item) {
            $key = $item->getAttribute('name') ?: $i++;
            $helper = $item->getAttribute('helper');

            if (!$item->hasChildren() && !$helper) {
                $value = (string) $item[0];

                switch ($item->getAttribute('type')) {
                    case 'path':
                        $value = $this->currentPath . '/' . self::DIR . '/' . $value;
                        break;
                    case 'const':
                        $value = constant($value);
                        break;
                    case 'int':
                        $value = (int) $value;
                        break;
                    case 'boolean':
                        if ($value === 'false') {
                            $value = false;
                        } else {
                            $value = (bool) $value;
                        }
                        break;
                }

                $result[$key] = $value;
                continue;
            }

            $arguments = $this->parseArguments($item->children());

            if ($helper) {
                $result[$key] = [
                    'helper' => $helper,
                    'arguments' => $arguments,
                ];
            } else {
                $result[$key] = $arguments;
            }
        }

        return $result;
    }
}