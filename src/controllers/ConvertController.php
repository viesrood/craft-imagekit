<?php

declare(strict_types=1);

namespace viesrood\imagekit\controllers;

use Craft;
use craft\helpers\FileHelper;
use craft\web\Controller;
use craft\web\UploadedFile;
use viesrood\imagekit\Plugin;
use yii\base\InvalidConfigException;
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
        $this->requirePermission('utility:imagekit');

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
                if ($error = $this->validateUpload($uploaded)) {
                    return $this->failure($error);
                }

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
                if (!$this->isValidSourceUrl($sourceUrl)) {
                    return $this->failure('The source URL must be an http(s) URL or a Media Library path starting with "/".');
                }

                $transformed = $service->url($sourceUrl, $options);

                return $this->asJson([
                    'success' => true,
                    'source' => 'url',
                    'filePath' => null,
                    'originalUrl' => $sourceUrl,
                    'transformedUrl' => $transformed,
                ]);
            }

            return $this->failure('Choose a file or enter a source URL.');
        } catch (InvalidConfigException $e) {
            // Configuration errors are written to be user-actionable.
            return $this->failure($e->getMessage());
        } catch (\Throwable $e) {
            // Never leak arbitrary exception messages to the client.
            Craft::error('ImageKit conversion failed: ' . $e->getMessage(), __METHOD__);

            return $this->failure('The request failed. Check the Craft logs for details.', 500);
        }
    }

    /**
     * Validate an uploaded file against the configured extension allowlist,
     * a real (finfo-based) MIME sniff, and the size cap.
     *
     * @return string|null An error message, or null when the upload is valid.
     */
    private function validateUpload(UploadedFile $uploaded): ?string
    {
        if ($uploaded->getHasError() || !is_uploaded_file($uploaded->tempName)) {
            return 'The file upload failed. Try again.';
        }

        /** @var \viesrood\imagekit\models\Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        $extension = strtolower(pathinfo($uploaded->name, PATHINFO_EXTENSION));
        $allowed = array_map('strtolower', $settings->uploadAllowedExtensions);
        if (!in_array($extension, $allowed, true)) {
            return sprintf(
                'File type ".%s" is not allowed. Allowed: %s.',
                $extension,
                implode(', ', $allowed)
            );
        }

        $mimeType = (string)FileHelper::getMimeType($uploaded->tempName);
        if (!str_starts_with($mimeType, 'image/')) {
            return 'The file does not appear to be an image.';
        }

        $maxBytes = $settings->uploadMaxFileSize ?: Craft::$app->getConfig()->getGeneral()->maxUploadFileSize;
        if ($maxBytes > 0 && $uploaded->size > $maxBytes) {
            return sprintf(
                'The file is too large (%s). The maximum is %s.',
                Craft::$app->getFormatter()->asShortSize($uploaded->size),
                Craft::$app->getFormatter()->asShortSize($maxBytes)
            );
        }

        return null;
    }

    /**
     * A valid source is an http(s) URL (resolved via the ImageKit web proxy)
     * or a Media Library path starting with "/".
     */
    private function isValidSourceUrl(string $sourceUrl): bool
    {
        if (str_starts_with($sourceUrl, '/')) {
            return true;
        }

        if (filter_var($sourceUrl, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string)parse_url($sourceUrl, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }

    /**
     * A JSON failure response with a safe, client-facing message.
     */
    private function failure(string $message, int $statusCode = 400): Response
    {
        $this->response->setStatusCode($statusCode);

        return $this->asJson([
            'success' => false,
            'error' => $message,
        ]);
    }
}
