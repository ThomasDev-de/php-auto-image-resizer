<?php

namespace Webcito;

use Imagick;
use ImagickException;
use JetBrains\PhpStorm\NoReturn;

class ImageCache
{
    /**
     * An array of screen resolutions.
     *
     *  320px: für mobile Geräte
     *  480px: für mobile Geräte im Querformat
     *  768px: für Tablets
     *  992px: für kleine Laptops
     *  1200px: für Desktops
     *
     * @var array $resolutions
     * @see https://www.w3schools.com/howto/howto_css_media_query_breakpoints.asp for commonly used screen resolutions
     */
    protected static array $resolutions = [1200, 992, 768, 480, 320];
    protected static int $browserCache = 604800; // a week

    protected static string $documentRoot;

    protected int $maxScreenWidth;
    protected int $desiredWidth;
    protected string $sourceFolder;
    protected string $sourceImagePath;
    protected string $imageFile;
    protected string $targetImagePath;

    protected static array $defaults = [
        "cache" => "cache",
        "resolution" => null,
        "breakpoints" => [1200, 992, 768, 480, 320],
        "compressionQuality" => 100
    ];

    /**
     * Creates a new instance of the class which takes a source folder, an image file, and optionally a resolution
     *
     * @param array $options
     */
    #[NoReturn] public function __construct(array $options = [])
    {
        $options = array_replace(self::$defaults, $options);
        self::$documentRoot = DIRECTORY_SEPARATOR . trim($_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR);
        $requested_uri = parse_url(urldecode($_SERVER['REQUEST_URI']), PHP_URL_PATH);
        $this->sourceFolder = dirname($requested_uri) . DIRECTORY_SEPARATOR;
        $this->sourceImagePath = self::$documentRoot . $requested_uri;
        $this->imageFile = basename($requested_uri);

        if (!file_exists($this->sourceImagePath)) {
            http_response_code(404);
            exit("File not found: " . $this->sourceImagePath);
        }

        if (!is_readable($this->sourceImagePath)) {
            http_response_code(403);
            exit("File not readable: " . $this->sourceImagePath);
        }

        // If no resolution was passed, determine whether the user is on a mobile device,
        //and set a default resolution based on that
        if ($options['resolution'] === null) {
            $resolution = $this->isMobile() ? min($options["breakpoints"]) : max($options["breakpoints"]);
        } else {
            $resolution = (int)$options['resolution'];
        }

        // Set the max screen width to the provided or determined resolution
        $this->maxScreenWidth = $resolution;

        // Filter the resolution array to only those less than or equal to the selected resolution
        $filteredResolutions = array_filter($options["breakpoints"], function ($width) use ($resolution) {
            return $width <= $resolution;
        });

        // If the filtered resolutions array is empty, use the minimum value from the original array;
        // else, use the maximum value from the filtered array
        $this->desiredWidth = empty($filteredResolutions) ? min($options["breakpoints"]) : max($filteredResolutions);

        // Create a new Imagick object and read the source image into it
        $image = new Imagick();


        // Get the width of the image
        try {
            $image->readImage($this->sourceImagePath);
            $image->setImageCompressionQuality($options["compressionQuality"]);
            $imageWidth = $image->getImageWidth();
        } catch (ImagickException $e) {
            http_response_code(403);
            exit("Imagick error: " . $e->getMessage());
        }

        // If the image width is less than the max screen width, output the original image and return
        if ($imageWidth < $this->maxScreenWidth) {
            $this->targetImagePath = $this->sourceImagePath;
            $this->output($image);
        }

        // Construct the output image path, appending the desired width to the file name
        $cacheFolder = DIRECTORY_SEPARATOR . trim($options['cache'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $targetDirectory = implode('', [
            self::$documentRoot,
            $cacheFolder,
            $this->desiredWidth,
            $this->sourceFolder
        ]);

        // does the $cache_path directory exist already?
        $this->createDirIfNotExist($targetDirectory);

        $this->targetImagePath = $targetDirectory . '/' . $this->imageFile;

        // If the output image file already exists, output the original image and return
        if (file_exists($this->targetImagePath)) {
            if ($this->isCacheUpToDate($this->sourceImagePath, $this->targetImagePath)) {
                $this->output($image);
            }
        }

        // Scale the image to the desired width (keeping an aspect ratio),
        //write it to the output path, output it and return
        try {
            $image->scaleImage($this->desiredWidth, 0);
            $image->writeImage($this->targetImagePath);
        } catch (ImagickException $e) {
            http_response_code(403);
            exit("Imagick error: " . $e->getMessage());
        }
        $this->output($image);
    }

    protected function isMobile(): bool
    {
        return preg_match("/(android|avantgo|blackberry|bolt|boost|cricket|docomo|fone|hiptop|mini|mobi|palm|phone|pie|tablet|up\.browser|up\.link|webos|wos)/i", $_SERVER["HTTP_USER_AGENT"]) !== false;
    }

    /**
     * Creates a directory if it does not exist.
     *
     * @param string $dir The directory path to be created.
     *
     * @return void
     */
    protected function createDirIfNotExist(string $dir): void
    {
        if (!is_dir("$dir")) {
            // dir does not exist, so make it
            if (!mkdir("$dir", 0777, true)) {
                // and check again to protect against race conditions
                if (!is_dir("$dir")) {
                    // failed to make that directory
                    http_response_code(403);
                    exit("Failed to create cache directory at: .  $dir");
                }
            }
        }
    }

    /**
     * Checks if the cache is up-to date by comparing the modification dates of the source and target files.
     *
     * @param string $source The path to the source file.
     * @param string $target The path to the target file (cache).
     *
     * @return bool Returns true if the cache is up-to date, false otherwise.
     */
    protected function isCacheUpToDate(string $source, string $target): bool
    {
        return filemtime($target) >= filemtime($source);
    }

    /**
     * Outputs the image to the client's browser.
     *
     * @param Imagick $image The image to be outputted.
     *
     * @return void
     */
    #[NoReturn] protected function output(Imagick $image): void
    {
        try {
            $extension = $image->getImageFormat();
        } catch (ImagickException $e) {
            http_response_code(403);
            exit("Imagick error: " . $e->getMessage());
        }
        // Calculate the expiration time for the cache. 'Self::$browser_cache' represents cache duration in seconds.
        $expires = gmdate('D, d M Y H:i:s', time() + self::$browserCache) . ' GMT';

        // Send an HTTP header to control how caching is to be done
        header("Cache-Control: private, max-age=" . self::$browserCache);

        // Send an HTTP header to tell the time at which the URL will be considered to have expired.
        header('Expires: ' . $expires);

        // Send an HTTP header to indicate the size of the data to be sent.
        header('Content-Length: ' . filesize($this->targetImagePath));

        // Analyze the image format and set the correct Content-Type HTTP header.
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

        $image->clear();
        $image->destroy();

        // Read the file and write it to the output buffer.
        readfile($this->targetImagePath);

        // Terminate execution of the script.
        exit;
    }
}