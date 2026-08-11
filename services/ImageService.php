<?php
declare(strict_types=1);

class ImageService
{
    private array $config;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../config/uploads.php';
    }

    public function processUploadedImage(array $file, int $productId): string
    {
        $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('No image file was provided.');
        }
        if ($uploadError !== UPLOAD_ERR_OK) {
            throw new RuntimeException('The image upload failed. Please try again.');
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('The image upload failed validation.');
        }

        $extension = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, $this->config['allowed_extensions'], true)) {
            throw new RuntimeException('Unsupported image format. Please choose a JPG, JPEG or PNG image.');
        }

        if ((int)($file['size'] ?? 0) > (int)$this->config['max_file_size_bytes']) {
            throw new RuntimeException('Image is too large. Maximum allowed size is 2 MB.');
        }

        $mime = $this->detectMime($file['tmp_name']);
        if (!in_array($mime, $this->config['allowed_mimes'], true)) {
            throw new RuntimeException('Unsupported image format. Please choose a JPG, JPEG or PNG image.');
        }

        $info = @getimagesize($file['tmp_name']);
        if ($info === false || ($info[0] ?? 0) <= 0 || ($info[1] ?? 0) <= 0) {
            throw new RuntimeException('The file is not a valid image.');
        }

        $image = $this->loadImage($file['tmp_name'], $mime);
        if (!$image) {
            throw new RuntimeException('Unable to process image.');
        }

        $storedPath = $this->storeOptimized($image, $productId, $info[0], $info[1], $mime === 'image/png');

        imagedestroy($image);

        return $storedPath;
    }

    public function removeImageFile(?string $imagePath): void
    {
        if ($imagePath === null || $imagePath === '') {
            return;
        }
        $base = dirname(__DIR__, 1);
        $full = realpath($base . '/' . ltrim($imagePath, '/'));
        $uploadsRoot = realpath($base . '/uploads');
        if ($full === false || $uploadsRoot === false || !is_file($full)) {
            return;
        }
        if (strpos($full, $uploadsRoot) !== 0) {
            return;
        }
        @unlink($full);
    }

    private function detectMime(string $tmpPath): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, $tmpPath);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return strtolower($mime);
                }
            }
        }
        $info = @getimagesize($tmpPath);
        if ($info !== false) {
            return strtolower((string)($info['mime'] ?? ''));
        }
        return '';
    }

    private function loadImage(string $tmpPath, string $mime): ?object
    {
        if ($mime === 'image/png') {
            $image = @imagecreatefrompng($tmpPath);
        } else {
            $image = @imagecreatefromjpeg($tmpPath);
        }
        if (!$image) {
            return null;
        }
        if ($mime === 'image/png') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }
        return $image;
    }

    private function storeOptimized(object $image, int $productId, int $origWidth, int $origHeight, bool $isPng): string
    {
        $maxDim = (int)$this->config['max_dimension'];
        $width = $origWidth;
        $height = $origHeight;
        $resized = null;

        if ($width > $maxDim || $height > $maxDim) {
            if ($width >= $height) {
                $resizedWidth = $maxDim;
                $resizedHeight = max(1, (int)round($height * ($maxDim / $width)));
            } else {
                $resizedHeight = $maxDim;
                $resizedWidth = max(1, (int)round($width * ($maxDim / $height)));
            }
            $resized = imagecreatetruecolor($resizedWidth, $resizedHeight);
            if (!$resized) {
                throw new RuntimeException('Unable to process image.');
            }
            if ($isPng) {
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
            }
            if (!imagecopyresampled($resized, $image, 0, 0, 0, 0, $resizedWidth, $resizedHeight, $width, $height)) {
                imagedestroy($resized);
                throw new RuntimeException('Unable to process image.');
            }
            $output = $resized;
        } else {
            $output = $image;
        }

        $dir = (string)$this->config['upload_dir'];
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            if ($resized !== null) {
                imagedestroy($resized);
            }
            throw new RuntimeException('Unable to store image.');
        }

        $suffix = bin2hex(random_bytes(4));
        $relativeBase = rtrim((string)$this->config['upload_url_base'], '/') . '/p' . $productId . '_' . $suffix;

        if ($this->config['output_format'] === 'webp' && function_exists('imagewebp')) {
            $relativePath = $relativeBase . '.webp';
            $fullPath = $dir . '/' . basename($relativePath);
            $saved = @imagewebp($output, $fullPath, (int)$this->config['webp_quality']);
            if (!$saved) {
                if ($resized !== null) {
                    imagedestroy($resized);
                }
                throw new RuntimeException('Unable to process image.');
            }
        } else {
            $relativePath = $relativeBase . '.jpg';
            $fullPath = $dir . '/' . basename($relativePath);
            $saved = @imagejpeg($output, $fullPath, (int)$this->config['jpeg_quality']);
            if (!$saved) {
                if ($resized !== null) {
                    imagedestroy($resized);
                }
                throw new RuntimeException('Unable to process image.');
            }
        }

        if ($resized !== null) {
            imagedestroy($resized);
        }

        return $relativePath;
    }
}
