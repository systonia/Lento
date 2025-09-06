<?php

namespace Lento\OpenAPI;

use Lento\Lento;
use RuntimeException;
use Lento\Attributes\{Get, FileFormatter, Inject, Controller, Ignore};
use Lento\OpenAPI\OpenAPIGenerator;
use Lento\Router;
use Lento\Exceptions\NotFoundException;

/**
 *
 */
#[Ignore]
#[Controller('/openapi')]
class OpenAPIController
{

    /**
     * Undocumented function
     *
     * @return string
     */
    #[Get('swagger.html')]
    #[FileFormatter(filename: 'swagger.html', mimetype: 'text/html', download: false)]
    public function swagger(): string
    {
        $filename = 'swagger.html';
        $baseDir = __DIR__;
        $safeName = basename($filename);
        $path = "$baseDir/$safeName";

        if (!is_file($path) || !is_readable($path)) {
            throw new NotFoundException("...");
        }

        return file_get_contents($path);
    }

    /**
     * Undocumented function
     *
     * @return string
     */
    #[Get('lentodoc.html')]
    #[FileFormatter(filename: 'lentodoc.html', mimetype: 'text/html', download: false)]
    public function lentodoc(): string
    {
        $filename = 'lentodoc.html';
        $baseDir = __DIR__;
        $safeName = basename($filename);
        $path = "$baseDir/$safeName";

        if (!is_file($path) || !is_readable($path)) {
            throw new NotFoundException("...");
        }

        return file_get_contents($path);
    }

    /**
     * Undocumented function
     *
     * @return array
     */
    #[Get('/spec.json')]
    #[FileFormatter(filename: 'spec.json', mimetype: 'application/json', download: false)]
    public function spec(): array
    {
        $OpenAPI = new OpenAPIGenerator(Lento::getRouter());

        return $OpenAPI->generate();
    }
}
