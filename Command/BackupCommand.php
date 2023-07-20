<?php

namespace BM\BackupManagerBundle\Command;


use BackupManager\Filesystems\Destination;
use BackupManager\Manager;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Backup database.
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class BackupCommand extends Command
{
    protected static $defaultName = 'backup-manager:backup';

    private Manager $manager;
    private string $filePrefix;
    private Filesystem $filesystem;

    public function __construct(Manager $manager, string $filePrefix)
    {
        $this->manager = $manager;
        $this->filePrefix = $filePrefix;
        $this->filesystem = new Filesystem();
        parent::__construct();
    }


    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription('Starts a new backup.')
            ->addArgument('database', InputArgument::REQUIRED, 'What database configuration do you want to backup?')
            ->addArgument('destinations', InputArgument::IS_ARRAY, 'What storages do you want to upload the backup to? Must be array.')
            ->addOption('compression', 'c', InputOption::VALUE_OPTIONAL, 'How do you want to compress the file?', 'null')
            ->addOption('filename', 'name', InputOption::VALUE_OPTIONAL, 'A customized filename', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (null === $filename = $input->getOption('filename')) {
            $filename = $this->filePrefix . (new \DateTime())->format('Y-m-d_H-i-s');
        }

        $destinations = [];
        foreach ($input->getArgument('destinations') as $name) {
            $destinations[] = new Destination($name, $filename);
        }

        $database = $input->getArgument('database');
        $compression = $input->getOption('compression');

        $this->manager->makeBackup()->run($database, $destinations, $compression);
        $this->deleteFilesLargerThanAllowedMax($output, $filename, $destinations);

        return Command::SUCCESS;
    }

    private function deleteFilesLargerThanAllowedMax($output, $filename, $destinations): void
    {
        $path = null;
        foreach ($destinations as $destination) {
            if ('local' === $destination->destinationFilesystem()) {
                $path = $destination->destinationPath();
            }
        }

        if (empty($path)) {
            return;
        }

        $finder = (new Finder())
            ->in(dirname($path))
            ->name(["$filename.gz"])
            ->sortByModifiedTime()
            ->depth(['== 0'])
            ->files();

        $filesCount = $finder->count();

        /** @var array<int, SplFileInfo> $files */
        $files = iterator_to_array($finder);

        $maxFiles = 5;

        if ($filesCount > $maxFiles) {
            $filesToDeleteCount = $filesCount - $maxFiles;
            array_splice($files, $filesToDeleteCount);

            if (1 === $filesToDeleteCount) {
                $output->warning('Reached the max backup files limit, removing the oldest one');
            } else {
                $output->warning(sprintf(
                    'Reached the max backup files limit, removing the %d oldest ones',
                    $filesToDeleteCount
                ));
            }

            foreach ($files as $file) {
                if ($output->isVerbose()) {
                    $output->comment(sprintf('Deleting "%s"', $file->getRealPath()));
                }
                $this->filesystem->remove($file->getRealPath());
            }
        }
    }
}
