<?php

/*
 * The MIT License
 *
 * Copyright 2019 Austrian Centre for Digital Humanities.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

namespace acdhOeaw\arche\glb;

use RuntimeException;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use rdfInterface\DatasetNodeInterface;
use termTemplates\PredicateTemplate as PT;
use acdhOeaw\arche\lib\dissCache\ResponseCacheItem;
use acdhOeaw\arche\lib\dissCache\FileCache;
use acdhOeaw\arche\lib\RepoResourceInterface;

/**
 * Description of Resource
 *
 * @author zozlak
 */
class Resource {

    const DEFAULT_MAX_FILE_SIZE_MB = 1000;
    const DEFAULT_MIN_FILE_SIZE_MB = 50;
    const MIME                     = 'model/gltf-binary';

    /**
     * Gets the requested repository resource metadata and converts it to the thumbnail's
     * service ResourceMeta object.
     * 
     * @param array<mixed> $param
     */
    static public function cacheHandler(RepoResourceInterface $res,
                                        array $param, object $config,
                                        ?LoggerInterface $log = null): ResponseCacheItem {
        $resource = new self((string) $res->getUri(), $config, $log);
        return $resource->getResponse($res->getGraph());
    }

    private string $resUrl;
    private object $config;
    private LoggerInterface | null $log;

    public function __construct(string $resUrl, object $config,
                                ?LoggerInterface $log) {
        $this->resUrl = $resUrl;
        $this->config = $config;
        $this->log    = $log;
    }

    public function getResponse(DatasetNodeInterface $meta): ResponseCacheItem {
        $response = $this->checkMeta($meta);
        if ($response === null) {
            return $response;
        }

        $path    = $this->getThumbnailPath();
        $modDate = new DateTimeImmutable($meta->getObjectValue(new PT($this->config->schema->modDate)));
        if (!file_exists($path) || filemtime($path) < $modDate->getTimestamp()) {
            $this->generateThumbnail();
        } else {
            $this->log?->info("Serving thumbnail model from cache $path");
        }

        $headers = [
            'Content-Type' => self::MIME,
            'Content-Size' => (string) filesize($path),
        ];
        return new ResponseCacheItem($path, 200, $headers, false, true);
    }

    private function checkMeta(DatasetNodeInterface $meta): ResponseCacheItem | null {
        $schema = $this->config->schema;

        $aclRead      = $meta->listObjects(new PT($schema->aclRead))->getValues();
        $allowedRoles = array_intersect($aclRead, $this->config->allowedAclRead);
        if (count($allowedRoles) === 0) {
            return new ResponseCacheItem('Unauthorized', 401);
        }

        $mime = $meta->getObjectValue(new PT($schema->mime));
        if ($mime !== self::MIME) {
            return new ResponseCacheItem("Unsupported resource format ($mime). Only " . self::MIME . " is supported.", 400);
        }

        $sizeLimitMb = $this->config->maxFileSizeMb ?? self::DEFAULT_MAX_FILE_SIZE_MB;
        $sizeMb      = ((int) $meta->getObjectValue(new PT($schema->size))) >> 20;
        if ($sizeMb > $sizeLimitMb) {
            return new ResponseCacheItem("Resource size ($sizeMb MB) exceeds the limit ($sizeLimitMb MB", 400);
        }
    }

    private function generateThumbnail(): void {
        $path      = $this->getThumbnailPath();
        $pathTmp   = $path . rand(0, 100000) . '.glb';
        $fileCache = new FileCache($this->config->cache->dir, $this->log, (array) $this->config->localAccess);
        //$refPath   = $fileCache->getRefFilePath($this->resUrl, self::MIME);
        $refPath   = '/var/www/html/model.glb';

        // model small enough to be server as it is
        $sizeMb    = ((int) filesize($refPath)) >> 20;
        $minSizeMb = $this->config->minFileSizeMb ?? self::DEFAULT_MIN_FILE_SIZE_MB;
        if ($sizeMb <= $minSizeMb) {
            copy($path, $pathTmp);
            if (!file_exists($path)) {
                rename($pathTmp, $path);
            } else {
                unlink($pathTmp);
            }
            $this->log?->debug("Small model ($sizeMb MB) - serving as it is");
            return;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        // The problem is we have no idea how much optimization can be achieved
        // by just symplyfying messy meshes and compressing textures better.
        // Therefore if source file is bigger the our arbitrarily taken size,
        // we first just run it trough `gltfpack -tp -tc -sa -si 0.99` and only
        // if the output is still too big, we rescale the mesh and/or textures
        // based on the ration between the initialy optimized model metrics
        // and desired metrics.
        $cmd = [
            'gltfpack', '-i', $refPath, '-o', $pathTmp, '-cc',
            '-tp', '-tc',
            '-sa', '-si', '0.99'
        ];
        $this->runGltf($cmd, 'Model simplification failed');

        $sizeMb = ((int) filesize($pathTmp)) >> 20;
        if ($sizeMb > $minSizeMb) {
            $pathTmp2 = $path . rand(0, 100000) . '.glb';
            $this->simplify($pathTmp, $pathTmp2);
            rename($pathTmp2, $pathTmp);
        }

        if (!file_exists($path)) {
            rename($pathTmp, $path);
        } else {
            unlink($pathTmp);
        }
        $this->log?->info("Thumbnail model generated");
    }

    private function simplify(string $inPath, string $outPath): void {
        list($vertexSizeMb, $textureSizeMb) = $this->getStatistics($inPath);
        $targetSizeMb = (int) ( $this->config->minFileSizeMb ?? self::DEFAULT_MIN_FILE_SIZE_MB);
        $ratio        = $targetSizeMb / ($vertexSizeMb + $textureSizeMb);
        $this->log?->debug("Vertex size $vertexSizeMb MB, textures size $textureSizeMb MB, target size $targetSizeMb MB, ratio " . round($ratio, 3));

        $cmd = [
            'gltfpack', '-i', $inPath, '-o', $outPath, '-cc',
            '-tp', '-tc', '-ts', (string) sqrt($ratio),
            '-sa', '-si', (string) $ratio
        ];
        $this->runGltf($cmd, 'Model simplification failed');
    }

    /**
     * 
     * @return array<string, int>
     */
    private function getStatistics(string $path): array {
        $cmd    = ['gltf-transform', 'inspect', '--format', 'csv', $path];
        $output = $this->runGltf($cmd, 'Failed to analyze the mode');

        $stats = ['meshes' => [], 'textures' => []];

        $table = null;
        foreach ($output as $line) {
            $line = trim($line);
            if (empty($line)) {
                $table = null;
            } elseif (str_starts_with($line, '#,')) {
                $csvHeader = str_getcsv($line, ',');
                if (in_array('primitives', $csvHeader)) {
                    $table = 'meshes';
                } elseif (in_array('mimeType', $csvHeader)) {
                    $table = 'textures';
                }
            } elseif (!empty($table)) {
                $stats[$table][] = (object) array_combine($csvHeader, str_getcsv($line, ','));
            }
        }
        $vertexSizeMb  = array_sum(array_map(fn($x) => (int) $x->size, $stats['meshes'])) >> 20;
        $textureSizeMb = array_sum(array_map(fn($x) => (int) $x->size, $stats['textures'])) >> 20;
        return [$vertexSizeMb, $textureSizeMb];
    }

    /**
     * 
     * @param array<string> $cmd
     * @return array<string>
     */
    private function runGltf(array $cmd, string $errorMsg): array {
        $cmd[0] = match ($cmd[0] ?? '') {
            'gltf-transform' => $this->config->gltfTransformPath,
            'gltfpack' => $this->config->gltfpackPath,
        };
        $cmd    = implode(' ', array_map(fn($x) => escapeshellarg($x), $cmd));
        $output = $ret    = null;
        $this->log?->debug("Running $cmd");
        exec($cmd, $output, $ret);
        if ($ret !== 0) {
            $this->log?->error("$cmd failed with code $ret:\n" . implode("\n", $output));
            throw new RuntimeException($errorMsg, 500);
        }
        return $output;
    }

    /**
     * Returns expected cached file location (doesn't assure such a file exists).
     */
    private function getThumbnailPath(): string {
        return sprintf('%s/%s/thumb.glb', $this->config->cache->dir, hash('xxh128', $this->resUrl));
    }
}
