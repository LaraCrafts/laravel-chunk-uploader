<?php

namespace LaraCrafts\ChunkUploader\Driver;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LaraCrafts\ChunkUploader\Exception\UploadHttpException;
use LaraCrafts\ChunkUploader\Helper\ChunkHelpers;
use LaraCrafts\ChunkUploader\Identifier\Identifier;
use LaraCrafts\ChunkUploader\Range\RequestRange;
use LaraCrafts\ChunkUploader\Response\PercentageJsonResponse;
use LaraCrafts\ChunkUploader\StorageConfig;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

class DropzoneUploadDriver extends UploadDriver
{
    use ChunkHelpers;

    /**
     * @var string
     */
    private $fileParam;

    public function __construct($config)
    {
        $this->fileParam = $config['param'];
    }

    /**
     * {@inheritDoc}
     */
    public function handle(Request $request, Identifier $identifier, StorageConfig $config, Closure $fileUploaded = null): Response
    {
        if ($this->isRequestMethodIn($request, [Request::METHOD_POST])) {
            return $this->save($request, $identifier, $config, $fileUploaded);
        }

        throw new MethodNotAllowedHttpException([
            Request::METHOD_POST,
        ]);
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @param \LaraCrafts\ChunkUploader\Identifier\Identifier $identifier
     * @param StorageConfig $config
     * @param \Closure|null $fileUploaded
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function save(Request $request, Identifier $identifier, StorageConfig $config, Closure $fileUploaded = null): Response
    {
        $file = $request->file($this->fileParam);

        if (null === $file) {
            throw new BadRequestHttpException('File not found in request body');
        }

        if (!$file->isValid()) {
            throw new UploadHttpException($file->getErrorMessage());
        }

        if ($this->isMonolithRequest($request)) {
            return $this->saveMonolith($file, $identifier, $config, $fileUploaded);
        }

        $this->validateChunkRequest($request);

        return $this->saveChunk($file, $request, $config, $fileUploaded);
    }

    /**
     * @param Request $request
     *
     * @return bool
     */
    private function isMonolithRequest(Request $request)
    {
        return $request->post('dzuuid') === null
            && $request->post('dzchunkindex') === null
            && $request->post('dztotalfilesize') === null
            && $request->post('dzchunksize') === null
            && $request->post('dztotalchunkcount') === null
            && $request->post('dzchunkbyteoffset') === null;
    }

    /**
     * @param Request $request
     */
    private function validateChunkRequest(Request $request)
    {
        $request->validate([
            'dzuuid' => 'required',
            'dzchunkindex' => 'required',
            'dztotalfilesize' => 'required',
            'dzchunksize' => 'required',
            'dztotalchunkcount' => 'required',
            'dzchunkbyteoffset' => 'required',
        ]);
    }

    /**
     * @param UploadedFile $file
     * @param Identifier $identifier
     * @param StorageConfig $config
     * @param \Closure|null $fileUploaded
     *
     * @return Response
     */
    private function saveMonolith(UploadedFile $file, Identifier $identifier, StorageConfig $config, Closure $fileUploaded = null): Response
    {
        $filename = $identifier->generateUploadedFileIdentifierName($file);

        $path = $file->storeAs($config->getMergedDirectory(), $filename, [
            'disk' => $config->getDisk(),
        ]);

        $this->triggerFileUploadedEvent($config->getDisk(), $path, $fileUploaded);

        return new PercentageJsonResponse(100);
    }

    /**
     * @param UploadedFile $file
     * @param Request $request
     * @param StorageConfig $config
     * @param \Closure|null $fileUploaded
     *
     * @return Response
     */
    private function saveChunk(UploadedFile $file, Request $request, StorageConfig $config, Closure $fileUploaded = null): Response
    {
        $numberOfChunks = $request->post('dztotalchunkcount');

        $range = new RequestRange(
            $request->post('dzchunkindex'),
            $numberOfChunks,
            $request->post('dzchunksize'),
            $request->post('dztotalfilesize')
        );

        $filename = $request->post('dzuuid');

        // On windows you can not create a file whose name ends with a dot
        if ($file->getClientOriginalExtension()) {
            $filename .= '.' . $file->getClientOriginalExtension();
        }

        $chunks = $this->storeChunk($config, $range, $file, $filename);

        if (!$this->isFinished($numberOfChunks, $chunks)) {
            return new PercentageJsonResponse($this->getPercentage($chunks, $numberOfChunks));
        }

        $path = $this->mergeChunks($config, $chunks, $filename);

        if (!empty($config->sweep())) {
            Storage::disk($config->getDisk())->deleteDirectory($filename);
        }

        $this->triggerFileUploadedEvent($config->getDisk(), $path, $fileUploaded);

        return new PercentageJsonResponse(100);
    }

    /**
     * @param $numberOfChunks
     * @param $chunks
     *
     * @return bool
     */
    private function isFinished($numberOfChunks, $chunks)
    {
        return $numberOfChunks === count($chunks);
    }

    /**
     * @param array $chunks
     * @param $numberOfChunks
     *
     * @return float|int
     */
    private function getPercentage(array $chunks, $numberOfChunks)
    {
        return floor(count($chunks) / $numberOfChunks * 100);
    }
}
