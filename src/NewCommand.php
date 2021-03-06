<?php

namespace Vatgia\Installer\Console;

use Symfony\Component\Console\Input\Input;
use Symfony\Component\Console\Style\SymfonyStyle;
use ZipArchive;
use RuntimeException;
use GuzzleHttp\Client;
use Symfony\Component\Process\Process;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

class NewCommand extends Command
{

    /**
     * @var Input
     */
    protected $input;

    /**
     * @var SymfonyStyle
     */
    protected $output;

//    protected $viewZipLink = 'http://git.vatgia.vn/vnp-framework/view/-/archive/master/view-master.zip';
    protected $viewZipLink = 'https://github.com/vatgia/vnp-framework/archive/master.zip';

    protected $appZipLink = 'http://git.vatgia.vn/vnp-framework/app/-/archive/master/app-master.zip';

    protected $databaseZipLink = 'http://git.vatgia.vn/vnp-framework/database/-/archive/master/database-master.zip';

    protected $docsZipLink = 'http://git.vatgia.vn/vnp-framework/docs/-/archive/master/docs-master.zip';

    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('new')
            ->setDescription('Create a new VNP application.')
            ->addArgument('name', InputArgument::OPTIONAL)
            ->addOption('force', null, InputOption::VALUE_NONE, 'Forces install even if the directory already exists')
            ->addOption('view', 'dv', InputOption::VALUE_NONE, 'Install with app folder. For dev view');
    }

    /**
     * Execute the command.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface $input
     * @param  \Symfony\Component\Console\Output\OutputInterface $output
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('The Zip PHP extension is not installed. Please install it and try again.');
        }

        $this->input = $input;

        $output = new SymfonyStyle($input, $output);

        $this->output = $output;

        $name = $input->getArgument('name');
        $name = ($name == '.') ? '' : $name;

        $directory = ($name) ? getcwd() . '/' . $name : getcwd();

        if (!$input->getOption('force')) {
            $this->verifyApplicationDoesntExist($directory);
        }

        $output->writeln('<info>Crafting application...</info>');

        $output->writeln('<info>Download code...</info>');

        //Download view
        $this->download($zipFile = $this->makeFilename())
            ->extract($zipFile, $directory)
            ->cleanUp($zipFile);

        $this->moveAllFile($directory . '/vnp-framework-master/', $directory);
        $this->rrmdir($directory . '/vnp-framework-master/');

        $this->prepareWritableDirectories($directory, $output);


//        if (!$input->getOption('view')) {
//
//            $output->writeln('<info>Download app...</info>');
//            //Download app
//            $this->downloadApp($zipFile = $this->makeFilename())
//                ->extract($zipFile, $directory . '/app')
//                ->cleanUp($zipFile);
//
//            $this->moveAllFile($directory . '/app/app.git/', $directory . '/app/');
//            $this->rrmdir($directory . '/app/app.git/');
//
//            /**
//             * Download Database
//             */
//            $output->writeln('<info>Download database...</info>');
//            //Download app
//            $this->downloadDatabase($zipFile = $this->makeFilename())
//                ->extract($zipFile, $directory . '/database')
//                ->cleanUp($zipFile);
//
//            $this->moveAllFile($directory . '/database/database.git/', $directory . '/database/');
//            $this->rrmdir($directory . '/database/database.git/');
//        }


        $composer = $this->findComposer();
        $database_composer = $this->findComposer('database');

        $commands = [
            $composer . ' install --no-scripts',
            $composer . ' run-script post-root-package-install',
            //$composer . ' run-script post-install-cmd',
            //$composer . ' run-script post-create-project-cmd',
            //Database
            'cd database',
            $database_composer . ' install --no-scripts',
        ];

        if ($input->getOption('no-ansi')) {
            $commands = array_map(function ($value) {
                return $value . ' --no-ansi';
            }, $commands);
        }

        $process = new Process(implode(' && ', $commands), $directory, null, null, null);

        if ('\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
            $process->setTty(true);
        }

        $process->run(function ($type, $line) use ($output) {
            $output->write($line);
        });

        $output->writeln('<comment>VNP Application ready! Build something amazing.</comment>');
    }

    /**
     * Verify that the application does not already exist.
     *
     * @param  string $directory
     * @return void
     */
    protected function verifyApplicationDoesntExist($directory)
    {
        if ((is_dir($directory) || is_file($directory)) && $directory != getcwd()) {
            throw new RuntimeException('Application already exists!');
        }
    }

    /**
     * Generate a random temporary filename.
     *
     * @return string
     */
    protected function makeFilename()
    {
        return getcwd() . '/vnp_' . md5(time() . uniqid()) . '.zip';
    }

    /**
     * Download the temporary Zip to the given file.
     *
     * @param  string $zipFile
     * @param  string $version
     * @return $this
     */
    protected function download($zipFile)
    {
        $response = (new Client)->get($this->viewZipLink);
        file_put_contents($zipFile, $response->getBody());

        return $this;
    }

    /**
     * Download the temporary Zip to the given file.
     *
     * @param  string $zipFile
     * @param  string $version
     * @return $this
     */
    protected function downloadApp($zipFile)
    {
        $response = (new Client)->get($this->appZipLink);
        file_put_contents($zipFile, $response->getBody());

        return $this;
    }

    /**
     * Download the temporary Zip to the given file.
     *
     * @param  string $zipFile
     * @param  string $version
     * @return $this
     */
    protected function downloadDatabase($zipFile)
    {
        $response = (new Client)->get($this->databaseZipLink);
        file_put_contents($zipFile, $response->getBody());

        return $this;
    }

    /**
     * Extract the Zip file into the given directory.
     *
     * @param  string $zipFile
     * @param  string $directory
     * @return $this
     */
    protected function extract($zipFile, $directory)
    {

        $archive = new ZipArchive;

        $archive->open($zipFile);

        $archive->extractTo($directory);

        $archive->close();

        return $this;
    }


    // Function to remove folders and files
    protected function rrmdir($dir)
    {
        if (is_dir($dir)) {
            $files = scandir($dir);
            foreach ($files as $file)
                if ($file != "." && $file != "..") $this->rrmdir("$dir/$file");
            rmdir($dir);
        } else if (file_exists($dir)) unlink($dir);
    }

    // Function to Copy folders and files
    protected function rcopy($src, $dst)
    {
//        if (file_exists($dst))
//            $this->rrmdir($dst);
        if (is_dir($src)) {

            if (!is_dir($dst)) {
                mkdir($dst);
            }

            $files = scandir($src);
            foreach ($files as $file)
                if ($file != "." && $file != "..")
                    $this->rcopy("$src/$file", "$dst/$file");
        } else if (file_exists($src)) {
            copy($src, $dst);
        }
    }

    protected function moveAllFile($source, $destination)
    {

        $source = realpath($source) . '/';
        $destination = realpath($destination) . '/';

        $this->rcopy($source, $destination);

        return $this;

    }

    /**
     * Clean-up the Zip file.
     *
     * @param  string $zipFile
     * @return $this
     */
    protected function cleanUp($zipFile)
    {
        @chmod($zipFile, 0777);

        @unlink($zipFile);

        return $this;
    }

    /**
     * Make sure the storage and bootstrap cache directories are writable.
     *
     * @param  string $appDirectory
     * @param  \Symfony\Component\Console\Output\OutputInterface $output
     * @return $this
     */
    protected function prepareWritableDirectories($appDirectory, OutputInterface $output)
    {
        $filesystem = new Filesystem;

        try {

            $filesystem->chmod($appDirectory . DIRECTORY_SEPARATOR . "ipstore", 0755, 0000, true);
            $filesystem->chmod($appDirectory . DIRECTORY_SEPARATOR . "ipdberror", 0755, 0000, true);
            $filesystem->chmod($appDirectory . DIRECTORY_SEPARATOR . "storage", 0755, 0000, true);

        } catch (IOExceptionInterface $e) {
            $output->writeln('<comment>You should verify that the "ipstore" and "ipdberror" directories are writable.</comment>');
        }

        return $this;
    }

    /**
     * Get the composer command for the environment.
     *
     * @return string
     */
    protected function findComposer($dir = null)
    {
        if (file_exists(getcwd() . ($dir ? ('/' . $dir) : '') . '/composer.phar')) {
            return '"' . PHP_BINARY . '" composer.phar';
        }

        return 'composer';
    }
}
