<?php

/*
 * The MIT License
 *
 * Copyright 2024 zozlak.
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

namespace acdhOeaw\arche\thumbnails\tests;

use DateTimeImmutable;
use quickRdf\DataFactory as DF;
use quickRdf\DatasetNode;
use acdhOeaw\arche\lib\RepoResourceInterface;
use acdhOeaw\arche\glb\Resource;
use acdhOeaw\arche\lib\dissCache\ResponseCacheItem;

/**
 * Description of ResourceTest
 *
 * @author zozlak
 */
class ResourceTest extends \PHPUnit\Framework\TestCase {

    static private object $config;
    static private object $schema;

    static public function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        self::$config = json_decode(json_encode(yaml_parse_file(__DIR__ . '/config.yaml')));
        self::$schema = new \stdClass();
        foreach (self::$config->schema as $k => $v) {
            self::$schema->$k = DF::namedNode($v);
        }
    }

    public function setUp(): void {
        parent::setUp();

        mkdir(self::$config->cache->dir, recursive: true);
        foreach ((array) (self::$config->localAccess ?? []) as $i) {
            if (!file_exists($i->dir)) {
                mkdir($i->dir, recursive: true);
            }
        }
    }

    public function tearDown(): void {
        parent::tearDown();

        system('rm -fR "' . self::$config->cache->dir . '"');
        foreach ((array) (self::$config->localAccess ?? []) as $i) {
            system('rm -fR "' . $i->dir . '"');
        }
    }

    public function testCacheHandler(): void {
        $modDate = '2024-10-16 09:46:30';
        $resUri  = DF::namedNode('https://arche-dev.acdh-dev.oeaw.ac.at/api/262627');
        $graph   = new DatasetNode($resUri);
        $graph->add(DF::quad($resUri, self::$schema->modDate, DF::literal($modDate)));
        $res     = $this->createStub(RepoResourceInterface::class);
        $res->method('getUri')->willReturn($graph->getNode());
        $res->method('getGraph')->willReturn($graph);

        // unauthorized
        $graph->add(DF::quad($resUri, self::$schema->aclRead, DF::literal('foo')));
        $resp = Resource::cacheHandler($res, [], self::$config, null);
        $this->assertEquals(new ResponseCacheItem('Unauthorized', 401), $resp);

        $graph->add(DF::quad($resUri, self::$schema->aclRead, DF::literal('public')));
        $resp = Resource::cacheHandler($res, [], self::$config, null);
        $this->assertEquals(new ResponseCacheItem('Unsupported resource format (). Only model/gltf-binary is supported.', 400), $resp);

        $mime = DF::quad($resUri, self::$schema->mime, DF::literal('image/png'));
        $graph->add($mime);
        $resp = Resource::cacheHandler($res, [], self::$config, null);
        $this->assertEquals(new ResponseCacheItem('Unsupported resource format (image/png). Only model/gltf-binary is supported.', 400), $resp);

        $graph->delete($mime);
        $graph->add($mime->withObject(DF::literal('model/gltf-binary')));
        $resp = Resource::cacheHandler($res, [], self::$config, null);
        $this->assertEquals($this->getRefResponse($resUri), $resp);

        $graph->add(DF::quad($resUri, self::$schema->size, DF::literal(2 << 30)));
        $resp = Resource::cacheHandler($res, [], self::$config, null);
        $this->assertEquals(new ResponseCacheItem('Resource size (2048 MB) exceeds the limit (1000 MB)', 400), $resp);
    }

    public function testGetResponse(): void {
        $resUrl  = 'https://arche-dev.acdh-dev.oeaw.ac.at/api/262627';
        $resMeta = $this->getResourceMeta($resUrl, '2025-01-01');
        $res     = new Resource($resMeta, self::$config, null);

        $resp = $res->getResponse();
        $this->assertEquals($this->getRefResponse($resUrl), $resp);

        $resp = $res->getResponse();
        $this->assertEquals($this->getRefResponse($resUrl, true), $resp);
    }

    private function getRefResponse(string $url, bool $hit = false): ResponseCacheItem {
        $refPath    = self::$config->cache->dir . '/' . hash('xxh128', $url) . '/thumb.glb';
        $refHeaders = [
            'Content-Type' => 'model/gltf-binary',
            'Content-Size' => (string) filesize($refPath),
        ];
        return new ResponseCacheItem($refPath, 200, $refHeaders, $hit, true);
    }

    private function getResourceMeta(string $url, string $modDate): DatasetNode {
        $sbj  = DF::namedNode($url);
        $meta = new DatasetNode($sbj);
        $meta->add(DF::quad($sbj, self::$schema->modDate, DF::literal($modDate)));
        $meta->add(DF::quad($sbj, self::$schema->aclRead, DF::literal('public')));
        $meta->add(DF::quad($sbj, self::$schema->mime, DF::literal('model/gltf-binary')));
        return $meta;
    }
}
