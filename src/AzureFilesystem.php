<?php

namespace TorqIT\PimcoreDatabaseBackupBundle;

use League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter;
use League\Flysystem\Filesystem as Flysystem;
use BackupManager\Filesystems\Filesystem;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;

class AzureFilesystem implements Filesystem
{
    public function handles($type): bool
    {
    }

    public function get(array $config): Flysystem
    {
        $client = BlobRestProxy::createBlobService('DefaultEndpointsProtocol=https;AccountName=' . $_ENV['AZURE_STORAGE_ACCOUNT_NAME'] . ';AccountKey=' . $_ENV['AZURE_STORAGE_ACCOUNT_KEY'] . ';EndpointSuffix=core.windows.net');
        return new Flysystem(new AzureBlobStorageAdapter($client, $_ENV['AZURE_STORAGE_ACCOUNT_CONTAINER']));
    }
}