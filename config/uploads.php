<?php
declare(strict_types=1);

return [
    'allowed_extensions' => ['jpg', 'jpeg', 'png'],
    'allowed_mimes' => ['image/jpeg', 'image/png'],
    'max_file_size_bytes' => 2 * 1024 * 1024,
    'max_dimension' => 1000,
    'output_format' => function_exists('imagewebp') ? 'webp' : 'jpg',
    'webp_quality' => 82,
    'jpeg_quality' => 85,
    'upload_dir' => __DIR__ . '/../uploads/products',
    'upload_url_base' => 'uploads/products',
];
