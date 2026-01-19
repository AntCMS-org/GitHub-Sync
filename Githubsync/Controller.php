<?php

/**
 * Copyright 2025 AntCMS
 */

namespace AntCMS\Plugins\Githubsync;

use AntCMS\AbstractPlugin;
use AntCMS\AntYaml;
use AntCMS\Event;
use AntCMS\HookController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

class Controller extends AbstractPlugin
{
    private readonly Filesystem $filesystem;
    private readonly string $configPath;
    private string $owner;
    private string $repo;
    private string $branch;
    private string $dest;

    private ?string $githubToken = null;
    private ?string $lastSha = null;
    private int $lastSyncTime;

    public function __construct()
    {
        $this->filesystem = new Filesystem();
        $this->configPath = Path::join(__DIR__, 'Config.yaml');
        HookController::registerCallback('onBeforeCronRun', $this->onBeforeCronRun(...));
    }

    public function onBeforeCronRun(Event $event): void
    {
        if (!$this->filesystem->exists($this->configPath)) {
            return;
        }

        $config = AntYaml::parseFile($this->configPath);

        if (empty($config['owner']) || empty($config['repo'])) {
            return;
        }

        $config['lastSync'] ??= [];

        $this->owner = $config['owner'];
        $this->repo = $config['repo'];
        $this->branch = $config['branch'] ?? 'main';
        $this->githubToken = $config['githubToken'] ?? null;
        $this->lastSha = $config['lastSync']['sha'] ?? null;
        $this->lastSyncTime = intval($config['lastSync']['time'] ?? 0);

        if (empty($config['targetDir'])) {
            error_log("To use the Githubsync plugin, please specify a destination directory.");
            return;
        }

        $this->dest = Path::join(PATH_CONTENT, $config['targetDir']);

        if (!$this->filesystem->exists($this->dest) || !is_dir($this->dest)) {
            error_log("Githubsync: The destination path {$this->dest} does not exist or is not a directory.");
            return;
        }

        try {
            $syncInterval = $this->githubToken ? 300 : 3600;
            if ($this->lastSyncTime && $this->lastSyncTime + $syncInterval <= time()) {
                return;
            }

            $latestSha = $this->getLatestCommitSha();

            // If already synced
            if ($this->lastSha === $latestSha) {
                return;
            }

            // Download and extract
            $this->downloadAndExtract();

            // Update YAML with last sync info
            $config['lastSync'] = [
                'sha' => $latestSha,
                'time' => date('c'),
            ];
            AntYaml::SaveFile($this->configPath, $config);
        } catch (\Exception $e) {
            error_log("GitHubSync failed: " . $e->getMessage());
        }
    }

    private function getLatestCommitSha(): string
    {
        $apiUrl = "https://api.github.com/repos/{$this->owner}/{$this->repo}/commits/{$this->branch}";

        $headers = [
            "User-Agent: PHP",
            "Accept: application/vnd.github+json",
        ];

        if ($this->githubToken) {
            $headers[] = "Authorization: Bearer {$this->githubToken}";
        }

        $context = stream_context_create([
            'http' => ['header' => implode("\r\n", $headers)],
        ]);

        $json = file_get_contents($apiUrl, false, $context);
        if ($json === false) {
            throw new \RuntimeException('GitHub API request failed');
        }

        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        return $data['sha'] ?? throw new \RuntimeException('Could not get latest commit SHA');
    }

    private function downloadAndExtract(): void
    {
        // Download the repo and write it to a temp file
        $zipUrl = "https://github.com/{$this->owner}/{$this->repo}/archive/refs/heads/{$this->branch}.zip";
        $tempZip = $this->filesystem->tempnam(PATH_CACHE, 'githubsync_', '.zip');

        $zipData = file_get_contents($zipUrl);
        if ($zipData === false) {
            throw new \RuntimeException('Failed to download repository ZIP');
        }

        $this->filesystem->dumpFile($tempZip, $zipData);

        // Verify we can open the zip file
        $zipArchive = new \ZipArchive();
        if ($zipArchive->open($tempZip) !== true) {
            throw new \RuntimeException('Failed to open ZIP file');
        }

        // If we can open it, extract it and delete the temporary zip file
        $tmpExtract = sys_get_temp_dir() . '/githubsync_extract_' . uniqid();
        $this->filesystem->mkdir($tmpExtract, 0o775);
        $zipArchive->extractTo($tmpExtract);
        $zipArchive->close();
        $this->filesystem->remove($tempZip);

        // Verify expected zip structure
        $rootDir = glob($tmpExtract . '/*', GLOB_ONLYDIR)[0] ?? null;
        if (!$rootDir) {
            $this->filesystem->remove($tmpExtract);
            throw new \RuntimeException('Unexpected ZIP structure');
        }

        // Finally if all checks passed, we can empty the destination folder
        $this->filesystem->remove($this->dest);
        $this->filesystem->mkdir($this->dest, 0o755);

        // Move contents to targetDir and perform cleanup
        $this->filesystem->rename($rootDir, $this->dest, true);
        $this->filesystem->remove($tmpExtract);
    }
}
