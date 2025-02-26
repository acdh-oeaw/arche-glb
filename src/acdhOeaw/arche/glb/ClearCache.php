<?php

/*
 * The MIT License
 *
 * Copyright 2025 Austrian Centre for Digital Humanities.
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

use BadMethodCallException;
use DirectoryIterator;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Description of ClearCache
 *
 * @author zozlak
 */
class ClearCache {

    const BY_MOD_TIME = 1;
    const BY_SIZE     = 2;

    /**
     * 
     * @var string
     */
    private $dir;

    /**
     * 
     * @param string $cacheDir
     */
    public function __construct(string $cacheDir) {
        $this->dir = $cacheDir;
    }

    /**
     * Assures caches doesn't exceed a given size.
     * @param int $maxSizeMb maximum cache size in MB
     * @param int $mode which files should be removed first
     *   - `ClearCache::BY_MOD_TIME` - oldest
     *   - `ClearCache::BY_SIZE` - biggest
     * @throws BadMethodCallException
     */
    public function clean(int $maxSizeMb, int $mode): void {
        if (!in_array($mode, [self::BY_MOD_TIME, self::BY_SIZE])) {
            throw new BadMethodCallException('unknown mode parameter value');
        }

        // collect information on files in the cache
        $flags     = FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::SKIP_DOTS;
        $dirIter   = new RecursiveDirectoryIterator($this->dir, $flags);
        $dirIter   = new RecursiveIteratorIterator($dirIter);
        $bySize    = [];
        $byModTime = [];
        $sizeSum   = 0;
        foreach ($dirIter as $i) {
            if ($i->isFile()) {
                $size                         = $i->getSize() / 1024 / 1024;
                $bySize[$i->getPathname()]    = $size;
                $byModTime[$i->getPathname()] = $i->getMTime();
                $sizeSum                      += $size;
            }
        }
        arsort($byModTime);
        asort($bySize);

        // assure cache size limit
        if ($sizeSum > $maxSizeMb) {
            $files = array_keys($mode === self::BY_SIZE ? $bySize : $byModTime);
            while ($sizeSum > $maxSizeMb && count($files) > 0) {
                $file = array_pop($files);
                echo "removing $file \n";
                unlink((string) $file);
                $sizeSum -= $bySize[$file];
            }
        }

        // remove empty directories
        $dirIter = new DirectoryIterator($this->dir);
        foreach($dirIter as $i){
            if (!$i->isDot() && count(scandir($i->getPathname()) ?: []) === 2) {
                rmdir($i->getPathname());
            }
        }
    }

}
