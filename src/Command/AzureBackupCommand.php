<?php

namespace TorqIT\DatabaseBackupBundle\Command;

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
            ->setDescription('Command to backup the Pimcore database to an Azure Storage Account')
            ->addArgument('azure-storage-account', InputArgument::REQUIRED, 'The name of the Azure Storage Account in which to store the backup')
            ->addArgument('azure-storage-account-key', InputArgument::REQUIRED, 'Key used to gain access to the Storage Account')
            ->addArgument('database-host', InputArgument::REQUIRED, 'The database host')
            ->addArgument('database-name', InputArgument::REQUIRED, 'The database name')
            ->addArgument('database-user', InputArgument::REQUIRED, 'The database user')
            ->addArgument('database-password', InputArgument::REQUIRED, 'The database password');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $azureStorageAccount = $input->getArgument('azure-storage-account');
        $azureStorageAccountKey = $input->getArgument('azure-storage-account-key');
        $databaseHost = $input->getArgument('database-host');
        $databaseName = $input->getArgument('database-name');
        $databaseUser = $input->getArgument('database-user');
        $databasePassword = $input->getArgument('database-password');

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
        $filesystems->add($this->buildAzureFilesystem($azureStorageAccount, $azureStorageAccountKey));
        $filesystems->add(new LocalFilesystem());

        $databases = new DatabaseProvider(new Config([
            'database' => [
                'type' => 'mysql',
                'host' => $databaseHost,
                'port' => '3306',
                'database' => $databaseName,
                'user' => $databaseUser,
                'pass' => $databasePassword,
                'singleTransaction' => false
            ],
        ]));
        $databases->add(new MysqlDatabase());

        $compressors = new CompressorProvider();
        $compressors->add(new GzipCompressor());

        $manager = new Manager($filesystems, $databases, $compressors);

        $manager
            ->makeBackup()
            ->run('database', [
                new Destination('azure', $databaseName . '-' . time() . '-backup.sql')
            ], 'gzip');

        return self::SUCCESS;
    }

    private function buildAzureFilesystem($azureStorageAccount, $azureStorageAccountKey): Filesystem
    {
        return new class($azureStorageAccount, $azureStorageAccountKey) implements Filesystem {
            public function __construct(private $azureStorageAccount, private $azureStorageAccountKey)
            {
            }

            public function handles($type): bool
            {
                return strtolower($type ?? '') == 'azure';
            }

            public function get(array $config): Flysystem
            {
                $client = BlobRestProxy::createBlobService('DefaultEndpointsProtocol=https;AccountName=' . $this->azureStorageAccount . ';AccountKey=' . $this->azureStorageAccountKey . ';EndpointSuffix=core.windows.net');
                return new Flysystem(new AzureBlobStorageAdapter($client, $this->azureStorageAccount));
            }
        };
    }
}