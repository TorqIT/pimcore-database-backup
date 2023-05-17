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
use Symfony\Component\Console\Input\InputArgument;

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
            ->setDescription('Command to backup a MySQL Pimcore database to an Azure Storage Account')
            ->addArgument('azure-storage-account-name', InputArgument::REQUIRED, 'The name of the Azure Storage Account in which to store the backup')
            ->addArgument('azure-storage-account-container', InputArgument::REQUIRED, 'The name of the Azure Storage Account container in which to store the backup')
            ->addArgument('azure-storage-account-key', InputArgument::REQUIRED, 'Key used to gain access to the Storage Account')
            ->addArgument('database-host', InputArgument::REQUIRED, 'The database host')
            ->addArgument('database-name', InputArgument::REQUIRED, 'The database name')
            ->addArgument('database-user', InputArgument::REQUIRED, 'The database user')
            ->addArgument('database-password', InputArgument::REQUIRED, 'The database password');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->fixBrokenLocalFlysystemImport();

        $azureStorageAccountName = $input->getArgument('azure-storage-account-name');
        $azureStorageAccountContainer = $input->getArgument('azure-storage-account-container');
        $azureStorageAccountKey = $input->getArgument('azure-storage-account-key');
        $databaseHost = $input->getArgument('database-host');
        $databaseName = $input->getArgument('database-name');
        $databaseUser = $input->getArgument('database-user');
        $databasePassword = $input->getArgument('database-password');

        $filesystems = new FilesystemProvider(new Config(
            [
                'local' => [
                    'type' => 'Local',
                    'root' => '/tmp'
                ],
                'azure' => [
                    'type' => 'azure',
                ],
            ]
        ));
        $filesystems->add($this->buildAzureFilesystem($azureStorageAccountName, $azureStorageAccountContainer, $azureStorageAccountKey));
        $filesystems->add(new LocalFilesystem);

        $databases = $this->buildDatabaseProvider($databaseHost, $databaseName, $databaseUser, $databasePassword);
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

    private function buildAzureFilesystem($azureStorageAccountName, $azureStorageAccountContainer, $azureStorageAccountKey): Filesystem
    {
        return new class($azureStorageAccountName, $azureStorageAccountContainer, $azureStorageAccountKey) implements Filesystem {
            public function __construct(private $azureStorageAccountName, private $azureStorageAccountContainer, private $azureStorageAccountKey)
            {
            }

            public function handles($type): bool
            {
                return strtolower($type ?? '') == 'azure';
            }

            public function get(array $config): Flysystem
            {
                $client = BlobRestProxy::createBlobService('DefaultEndpointsProtocol=https;AccountName=' . $this->azureStorageAccountName . ';AccountKey=' . $this->azureStorageAccountKey . ';EndpointSuffix=core.windows.net');
                return new Flysystem(new AzureBlobStorageAdapter($client, $this->azureStorageAccountContainer));
            }
        };
    }

    /**
     * This is necessary until https://github.com/backup-manager/backup-manager/issues/188 is fixed.
     */
    private function fixBrokenLocalFlysystemImport(): void
    {
        $pathToFile = "/var/www/html/vendor/backup-manager/backup-manager/src/Filesystems/LocalFilesystem.php";

        $originalText = "use League\Flysystem\Adapter\Local;";
        $textReplace = "use League\Flysystem\Local\LocalFilesystemAdapter as Local;";

        $fileChange = file_get_contents($pathToFile);
        $textContent = str_replace($originalText, $textReplace, $fileChange);
        file_put_contents($pathToFile, $textContent);
    }

    private function buildDatabaseProvider(mixed $databaseHost, mixed $databaseName, mixed $databaseUser, mixed $databasePassword): DatabaseProvider
    {
        return new DatabaseProvider(new Config([
            'database' => [
                'type' => 'mysql',
                'host' => $databaseHost,
                'port' => '3306',
                'database' => $databaseName,
                'user' => $databaseUser,
                'pass' => $databasePassword,
                'singleTransaction' => false,
                'ssl' => true,
                'extraParams' => '--ssl-ca=/var/www/html/config/db/DigiCertGlobalRootCA.crt.pem'
            ],
        ]));
    }
}