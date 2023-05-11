<?php

namespace TorqIT\PimcoreDatabaseBackupBundle;

use BackupManager\Compressors\CompressorProvider;
use BackupManager\Compressors\GzipCompressor;
use BackupManager\Config\Config;
use BackupManager\Databases\DatabaseProvider;
use BackupManager\Databases\MysqlDatabase;
use BackupManager\Filesystems\Destination;
use BackupManager\Filesystems\Filesystem;
use BackupManager\Filesystems\FilesystemProvider;
use BackupManager\Filesystems\LocalFilesystem;
use BackupManager\Manager;
use League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter;
use League\Flysystem\Filesystem as Flysystem;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use Pimcore\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AzureBackupCommand extends AbstractCommand
{
    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('torq:db-backup')
            ->setDescription('Command to backup the Pimcore database to an Azure Storage Account');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filesystems = new FilesystemProvider(new Config(
            [
                'Local' => [
                    'root' => '/tmp'
                ],
                'azure' => [
                    'type' => 'azure',
                ],
            ]
        ));
        $filesystems->add(new class implements Filesystem {
            public function handles($type): bool
            {
                return strtolower($type ?? '') == 'azure';
            }

            public function get(array $config): Flysystem
            {
                $client = BlobRestProxy::createBlobService('DefaultEndpointsProtocol=https;AccountName=' . $_ENV['AZURE_STORAGE_ACCOUNT_NAME'] . ';AccountKey=' . $_ENV['AZURE_STORAGE_ACCOUNT_KEY'] . ';EndpointSuffix=core.windows.net');
                return new Flysystem(new AzureBlobStorageAdapter($client, $_ENV['AZURE_STORAGE_ACCOUNT_CONTAINER']));
            }
        });
        $filesystems->add(new LocalFilesystem);

        $databases = new DatabaseProvider(new Config([
            'database' => [
                'type' => 'mysql',
                'host' => $_ENV['DATABASE_HOST'],
                'port' => '3306',
                'user' => $_ENV['DATABASE_USER'],
                'pass' => $_ENV['DATABASE_PASSWORD'],
                'database' => $_ENV['DATABASE_NAME'],
                'singleTransaction' => false
            ],
        ]));
        $databases->add(new MysqlDatabase);

        $compressors = new CompressorProvider;
        $compressors->add(new GzipCompressor);

        $manager = new Manager($filesystems, $databases, $compressors);

        $manager
            ->makeBackup()
            ->run('database', [
                new Destination('azure', $_ENV['DATABASE_NAME'] . '-' . time() . '-backup.sql')
            ], 'gzip');

        return self::SUCCESS;
    }

}