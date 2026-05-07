<?php
/**
 * Cloudinary Service
 * Handles image uploads to Cloudinary for persistent storage.
 */

// Use vendor autoload if it exists (for Railway/Composer)
$autoload = __DIR__ . '/../../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

use Cloudinary\Configuration\Configuration;
use Cloudinary\Api\Upload\UploadApi;

class CloudinaryService {
    private static $initialized = false;

    public static function init() {
        if (self::$initialized) return;
        
        $cloudinaryUrl = getenv('CLOUDINARY_URL');
        if (!$cloudinaryUrl) {
            // Fallback for local dev if not in getenv
            if (defined('CLOUDINARY_URL')) {
                $cloudinaryUrl = CLOUDINARY_URL;
            }
        }

        if ($cloudinaryUrl) {
            Configuration::instance($cloudinaryUrl);
            self::$initialized = true;
        }
    }

    /**
     * Upload a file to Cloudinary
     * @param string $filePath Local path or temp path of the file
     * @param string $folder Folder name in Cloudinary
     * @return string|null The secure URL of the uploaded image or null on failure
     */
    public static function upload($filePath, $folder = 'legacy_of_spices') {
        self::init();
        
        if (!self::$initialized) {
            error_log("Cloudinary Error: CLOUDINARY_URL not set.");
            return null;
        }

        try {
            $uploadApi = new UploadApi();
            $result = $uploadApi->upload($filePath, [
                'folder' => $folder,
                'resource_type' => 'auto',
                'quality' => 'auto',
                'fetch_format' => 'auto'
            ]);
            return $result['secure_url'];
        } catch (Exception $e) {
            error_log("Cloudinary Upload Exception: " . $e->getMessage());
            return null;
        }
    }
}
