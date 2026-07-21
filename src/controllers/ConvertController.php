<?php

declare(strict_types=1);

namespace viesrood\imagekit\controllers;

use Craft;
use craft\web\Controller;
use craft\web\UploadedFile;
use viesrood\imagekit\Plugin;
use yii\web\Response;

/**
 * Backend for the ImageKit utility. Uploads a file (optionally) or takes a
 * source URL, and returns the converted ImageKit URL + Media Library path as JSON.
 */
class ConvertController extends Controller
{
    public function actionRun(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $service = Plugin::getInstance()->getImagekit();

        $options = array_filter([
            'width' => $request->getBodyParam('width'),
            'height' => $request->getBodyParam('height'),
            'format' => $request->getBodyParam('format'),
            'quality' => $request->getBodyParam('quality'),
        ], static fn($v) => $v !== null && $v !== '');

        if ($request->getBodyParam('signed')) {
            $options['signed'] = true;
        }

        try {
            $uploaded = UploadedFile::getInstanceByName('file');
            $sourceUrl = trim((string)$request->getBodyParam('sourceUrl', ''));

            if ($uploaded !== null) {
                $result = $service->upload($uploaded->tempName, [
                    'fileName' => $uploaded->name,
                ]);
                $filePath = $result['filePath'];
                if ($filePath === null) {
                    throw new \RuntimeException('Upload did not return a filePath.');
                }
                $transformed = $service->url($filePath, $options);

                return $this->asJson([
                    'success' => true,
                    'source' => 'upload',
                    'filePath' => $filePath,
                    'fileId' => $result['fileId'],
                    'originalUrl' => $result['url'],
                    'transformedUrl' => $transformed,
                ]);
            }

            if ($sourceUrl !== '') {
                $transformed = $service->url($sourceUrl, $options);

                return $this->asJson([
                    'success' => true,
                    'source' => 'url',
                    'filePath' => null,
                    'originalUrl' => $sourceUrl,
                    'transformedUrl' => $transformed,
                ]);
            }

            return $this->asJson([
                'success' => false,
                'error' => 'Choose a file or enter a source URL.',
            ]);
        } catch (\Throwable $e) {
            Craft::error('ImageKit conversion failed: ' . $e->getMessage(), __METHOD__);

            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
