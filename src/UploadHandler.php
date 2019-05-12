<?php

namespace LaraCrafts\ChunkUploader;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Traits\Macroable;
use LaraCrafts\ChunkUploader\Driver\UploadDriver;
use LaraCrafts\ChunkUploader\Identifier\Identifier;
use Symfony\Component\HttpFoundation\Response;

class UploadHandler
{
    use Macroable;

    /**
     * @var \LaraCrafts\ChunkUploader\Driver\UploadDriver
     */
    protected $driver;

    /**
     * @var StorageConfig
     */
    protected $config;

    /**
     * @var \LaraCrafts\ChunkUploader\Identifier\Identifier
     */
    protected $identifier;

    /**
     * UploadHandler constructor.
     *
     * @param \LaraCrafts\ChunkUploader\Driver\UploadDriver $driver
     * @param \LaraCrafts\ChunkUploader\Identifier\Identifier $identifier
     * @param StorageConfig $config
     */
    public function __construct(UploadDriver $driver, Identifier $identifier, StorageConfig $config)
    {
        $this->driver = $driver;
        $this->config = $config;
        $this->identifier = $identifier;
    }

    /**
     * Save an uploaded file to the target directory.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @param \Closure $fileUploaded
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $fileUploaded = null): Response
    {
        return $this->driver->handle($request, $this->identifier, $this->config, $fileUploaded);
    }
}
