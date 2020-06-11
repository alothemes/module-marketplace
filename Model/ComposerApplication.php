<?php

namespace Swissup\Marketplace\Model;

use Composer\Console\Application;
use Composer\IO\BufferIO;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Composer\ComposerJsonFinder;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ComposerApplication
{
    /**
     * @var \Composer\Console\Application
     */
    protected $app;

    /**
     * @var string
     */
    protected $workingDir;

    /**
     * @var \Symfony\Component\Console\Output\OutputInterface
     */
    protected $consoleOutput;

    /**
     * @param DirectoryList $directoryList
     * @param ComposerJsonFinder $composerJsonFinder
     */
    public function __construct(
        DirectoryList $directoryList,
        ComposerJsonFinder $composerJsonFinder
    ) {
        $this->app = new Application();
        $this->app->setAutoExit(false);

        $this->workingDir = dirname($composerJsonFinder->findComposerJson());
        $this->consoleOutput = new BufferedOutput();

        putenv('COMPOSER_HOME=' . $directoryList->getPath(DirectoryList::COMPOSER_HOME));
    }

    /**
     * @param array $command
     * @return string
     * @throws \RuntimeException
     */
    public function run(array $command)
    {
        // https://github.com/composer/composer/commit/94df55425596c0137d0aa14485bba2fb05e15de6
        if (version_compare($this->app->getVersion(), '1.8.4', '<')) {
            unset($command['-q']);
        }

        $this->app->resetComposer();

        $input = new ArrayInput(array_merge([
            '--working-dir' => $this->workingDir,
        ], $command));

        $exitCode = $this->app->run($input, $this->consoleOutput);

        if ($exitCode) {
            throw new \RuntimeException(
                sprintf('Command "%s" failed: %s', $command['command'], $this->consoleOutput->fetch())
            );
        }

        return $this->consoleOutput->fetch();
    }

    /**
     * @param array $command
     * @return string
     */
    public function runAuthCommand(array $command)
    {
        return $this->run(array_merge([
            'command' => 'config',
            '-a' => true,
            '-g' => true,
        ], $command));
    }
}
