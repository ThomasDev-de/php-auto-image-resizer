<?php

namespace Webcito;

use Imagick;
use ImagickException;
use JetBrains\PhpStorm\NoReturn;

/**
 * Class ImageCache
 * This class provides functionality for optimizing images for web delivery.
 * It automatically caches the optimized version of the images to reduce server load.
 */
class ImageCache
{
    // Time for which the content can be cached in the browser (in seconds)
    protected static int $browserCache = 604800; // a week

    // DocumentRoot path, needed for accurately locating and serving images
    protected static string $documentRoot;
    // Maximum screen width for which the image is to be optimized. This is set based on the viewing device.
    protected int $maxScreenWidth;
    // The desired width of the output image after optimization. This is calculated based on the available breakpoints and device screen width.
    protected int $desiredWidth;
    // Folder which contains the source image
    protected string $sourceFolder;
    // Full file path to the source image
    protected string $sourceImagePath;
    // File name of the image file
    protected string $imageFile;
    // Full file path where the optimized (target) image will be stored
    protected string $targetImagePath;
    // Default settings for the image optimization process
    protected static array $defaults = [
        "cache" => "cache", // default name of the cache directory
        "resolution" => null, // default resolution (null, because it is set based on the device)
        "breakpoints" => [1200, 992, 768, 480, 320], // screen width breakpoints that guide the optimization
        "compressionQuality" => 85 // default quality of the output image
    ];

    /**
     * Constructs a new ImageCache instance, setting paths and calculating needed image dimensions.
     * Image path and filename are derived from the server's REQUEST_URI.
     *
     * @param array $options Override options for image caching. Can include custom breakpoints, cache folder name, resolution, and compression quality.
     */
    #[NoReturn] public function __construct(array $options = [])
    {
        // Replace default options with user provided ones
        $options = array_replace(self::$defaults, $options);
        // Parse the request URI to set image paths, folders and names
        // Trim and affix DIRECTORY_SEPARATOR to ensure path consistency
        self::$documentRoot = DIRECTORY_SEPARATOR . trim($_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR);
        // Parse the URL to retrieve the path
        $requested_uri = parse_url(urldecode($_SERVER['REQUEST_URI']), PHP_URL_PATH);
        // Set the source folder
        $this->sourceFolder = dirname($requested_uri) . DIRECTORY_SEPARATOR;
        // Set the source image path
        $this->sourceImagePath = self::$documentRoot . $requested_uri;
        // Set the image file name (includes an extension)
        $this->imageFile = basename($requested_uri);
        // Prompt the user with a message and halt if the source image does not exist, or if it isn't readable
        if (!file_exists($this->sourceImagePath)) {
            http_response_code(404);
            exit("File not found: " . $this->sourceImagePath);
        }

        if (!is_readable($this->sourceImagePath)) {
            http_response_code(403);
            exit("File not readable: " . $this->sourceImagePath);
        }

        // If no specific resolution was provided, determine whether the user is on a mobile device or not
        // Then use appropriate breakpoints for maximum performance
        if ($options['resolution'] === null) {
            $resolution = $this->isMobile() ? min($options["breakpoints"]) : max($options["breakpoints"]);
        } else {
            $resolution = (int)$options['resolution'];
        }
        // Set the maximum screen width
        $this->maxScreenWidth = $resolution;

        // Proceed through breakpoints picking those less or equal to the set resolution
        $filteredResolutions = array_filter($options["breakpoints"], function ($width) use ($resolution) {
            return $width <= $resolution;
        });
        // If none are found, use the minimum breakpoint available
        // If some are found, use the maximum viable breakpoint for the best quality
        $this->desiredWidth = empty($filteredResolutions) ? min($options["breakpoints"]) : max($filteredResolutions);

        // Create a new Imagick object and read image from previously set path/info, stripping unnecessary data, and set compression quality
        // Imagick assists in creating the new optimized image by reading the original and writing the optimized one
        $image = new Imagick();

        // Get width from the image, and capture any exceptions thrown by the Imagick class
        try {
            $image->readImage($this->sourceImagePath);

            // Get the current width of the source image
            $imageWidth = $image->getImageWidth();
        } catch (ImagickException $e) {
            // If there was an error (e.g. file not found, read access denied), send a 403 Forbidden status code and halt the script
            http_response_code(403);
            exit("Imagick error: " . $e->getMessage());
        }

        // If the source image width is less than the maximum screen width set, it means no resizing is necessary
        // So we set the source image as the target, output it directly, and halt the script
        if ($imageWidth < $this->maxScreenWidth) {
            $this->targetImagePath = $this->sourceImagePath;
            try {
                $this->output($image);
            } catch (ImagickException $e) {
                // If there was an error (e.g. write access denied), send an HTTP 403 Forbidden status code
                http_response_code(403);
                exit("Imagick error: " . $e->getMessage());
            }
        }

        // Construct the path for the cached (optimized) image version
        // If it doesn't exist, create it
        // targetDirectory: <document_root>/<cache_folder>/<desired_width>/<source_folder>/
        $cacheFolder = DIRECTORY_SEPARATOR . trim($options['cache'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $targetDirectory = implode('', [
            self::$documentRoot,
            $cacheFolder,
            $this->desiredWidth,
            $this->sourceFolder
        ]);

        // does the $cache_path directory exist already?
        $this->createDirIfNotExist($targetDirectory);

        // ./cache/desiredWidth/sourceFolder/imageFile
        $this->targetImagePath = $targetDirectory . '/' . $this->imageFile;


        try {
            // If the cached image file already exists and is up-to-date, directly output it
            if (file_exists($this->targetImagePath)) {
                if ($this->isCacheUpToDate($this->sourceImagePath, $this->targetImagePath)) {
                    $this->output($image);
                }
            }

            // If the cached image doesn't exist, or is outdated, optimize the source image
            // Scale the image down to the desired width (keeping an aspect ratio),
            // write it to the determined cache location, and output it
            $this->writeImage($image, $options);
            // Finally, output the image to the client
            $this->output($image);
        } catch (ImagickException $e) {
            // If there was an error (e.g. write access denied), send an HTTP 403 Forbidden status code
            http_response_code(403);
            exit("Imagick error: " . $e->getMessage());
        }


    }

    /**
     * @throws ImagickException
     */
    protected function writeImage(Imagick $image, array $options): void
    {
        // Strips the image of any profiles, comments - basically unnecessary metadata. This greatly decreases size.
        $image->stripImage();

        // Sets the quality of the image. Higher is better, but produces a larger file size.
        $this->setQualityBasedOnWidth(
            image: $image,
            maxWidth: max($options["breakpoints"]),
            minQuality: $options["compressionQuality"]
        );
        // The '0' indicates that the height should be auto-calculated based on an aspect ratio
        $image->scaleImage($this->desiredWidth, 0);
        // writing the image to the cache
        $image->writeImage($this->targetImagePath);
    }

    /**
     * Set the compression quality of the image based on its width
     *
     * @param Imagick $image The image object
     * @param int $maxWidth The maximum width threshold for setting the compression quality
     * @param int $minQuality The minimum compression quality to be set
     * @param int $maxQuality The maximum compression quality to be set
     * @return void
     * @throws ImagickException
     */
    protected function setQualityBasedOnWidth(Imagick $image, int $maxWidth = 1000, int $minQuality = 10, int $maxQuality = 100): void
    {
        $width = $image->getImageWidth();
        if ($width >= $maxWidth) {
            $image->setImageCompressionQuality($minQuality);
        } else {
            $quality = ($width / $maxWidth) * ($maxQuality - $minQuality) + $minQuality;
            $image->setImageCompressionQuality($quality);
        }
    }

    /**
     * Detect if the client is using a mobile device. Uses the user agent string to do so.
     *
     * @return bool: Returns true if the user agent matches that of a known mobile device, false otherwise.
     */
    protected function isMobile(): bool
    {
        // Match against a regular expression, which contains partial matches for known mobile User Agents
        return preg_match("/(android|avantgo|blackberry|bolt|boost|cricket|docomo|fone|hiptop|mini|mobi|palm|phone|pie|tablet|up\.browser|up\.link|webos|wos)/i", $_SERVER["HTTP_USER_AGENT"]) !== false;
    }

    /**
     * Creates a directory at the provided path if it doesn't already exist
     *
     * @param string $dir The path where the directory needs to be created
     * @return void
     */
    protected function createDirIfNotExist(string $dir): void
    {
        // Checks to see if the directory exists
        if (!is_dir("$dir")) {
            // If not, attempts to create the directory and checks again
            if (!mkdir("$dir", 0755, true)) {
                if (!is_dir("$dir")) {
                    // If unsuccessful, an HTTP 403 (Forbidden) response is sent
                    http_response_code(403);
                    exit("Failed to create cache directory at: .  $dir");
                }
            }
        }
    }

    /**
     * Checks if the cache for a source file is up-to-date based on the last modified timestamps of both files
     *
     * @param string $source The path to the source file
     * @param string $target The path to the target file (cached version)
     * @return bool Returns true if cache is up-to-date, otherwise, returns false
     */
    protected function isCacheUpToDate(string $source, string $target): bool
    {
        // File modification times are compared, and if the cached file is newer, it is considered up-to-date
        return filemtime($target) >= filemtime($source);
    }

    /**
     * Preparing HTTP headers and output the optimized/cached image
     *
     * @param Imagick $image The image to be outputted to the client
     * @return void
     * @throws ImagickException
     */
    #[NoReturn] protected function output(Imagick $image): void
    {
        // Tries to retrieve the image format (needed for the Content-Type header)

        $extension = $image->getImageFormat();

        // Set cache expiration headers
        $expires = gmdate('D, d M Y H:i:s', time() + self::$browserCache) . ' GMT';

        // Send an HTTP header to control how caching is to be done
        header("Cache-Control: private, max-age=" . self::$browserCache);

        // Send an HTTP header to tell the time at which the URL will be considered to have expired.
        header('Expires: ' . $expires);

        // Send an HTTP header to indicate the size of the data to be sent.
        header('Content-Length: ' . filesize($this->targetImagePath));

        // Based on the retrieved format, the appropriate Content-Type HTTP header is set
        switch (strtolower($extension)) {
            case 'jpeg':
                header('Content-type: image/jpeg');
                break;
            case 'png':
                header('Content-type: image/png');
                break;
            case 'webp':
            default:
                header('Content-type: image/webp');
                break;
        }

        // Once the image has been sent for output, it's no longer needed,
        // so both Imagick's allocated memory, and the image resource itself, are cleaned up
        $image->clear();
        $image->destroy();

        // Here we finally output the cached image file data. After this, script execution is halted
        readfile($this->targetImagePath);

        // End script execution
        exit;
    }
}
