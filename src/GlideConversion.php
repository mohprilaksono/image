<?php

namespace Spatie\Image;

use Exception;
use FilesystemIterator;
use League\Glide\Server;
use League\Glide\ServerFactory;
use Spatie\Image\Exceptions\CouldNotConvert;
use Spatie\Image\Exceptions\InvalidTemporaryDirectory;

final class GlideConversion
{
    private string $imageDriver = 'gd';

    private ?string $conversionResult = null;

    private string $temporaryDirectory;

    public function __construct(private string $inputImage)
    {
        $this->temporaryDirectory = sys_get_temp_dir();
    }

    public static function create(string $inputImage): self
    {
        return new self($inputImage);
    }

    public function setTemporaryDirectory(string $temporaryDirectory): self
    {
        if (! is_dir($temporaryDirectory)) {
            try {
                mkdir($temporaryDirectory);
            } catch (Exception) {
                throw InvalidTemporaryDirectory::temporaryDirectoryNotCreatable($temporaryDirectory);
            }
        }

        if (! is_writable($temporaryDirectory)) {
            throw InvalidTemporaryDirectory::temporaryDirectoryNotWritable($temporaryDirectory);
        }

        $this->temporaryDirectory = $temporaryDirectory;

        return $this;
    }

    public function getTemporaryDirectory(): string
    {
        return $this->temporaryDirectory;
    }

    public function useImageDriver(string $imageDriver): self
    {
        $this->imageDriver = $imageDriver;

        return $this;
    }

    public function performManipulations(Manipulations $manipulations): GlideConversion
    {
        foreach ($manipulations->getManipulationSequence() as $manipulationGroup) {
            $inputFile = $this->conversionResult ?? $this->inputImage;

            $watermarkPath = $this->extractWatermarkPath($manipulationGroup);

            $glideServer = $this->createGlideServer($inputFile, $watermarkPath);

            $glideServer->setGroupCacheInFolders(false);

            $manipulatedImage = $this->temporaryDirectory.DIRECTORY_SEPARATOR.$glideServer->makeImage(
                pathinfo($inputFile, PATHINFO_BASENAME),
                $this->prepareManipulations($manipulationGroup)
            );

            if ($this->conversionResult) {
                unlink($this->conversionResult);
            }

            $this->conversionResult = $manipulatedImage;
        }

        return $this;
    }

    /**
     * Removes the watermark path from the manipulationGroup and returns it.
     * This way it can be injected into the Glide server as the `watermarks` path.
     */
    private function extractWatermarkPath(&$manipulationGroup)
    {
        if (array_key_exists('watermark', $manipulationGroup)) {
            $watermarkPath = dirname($manipulationGroup['watermark']);

            $manipulationGroup['watermark'] = basename($manipulationGroup['watermark']);

            return $watermarkPath;
        }
    }

    private function createGlideServer($inputFile, string $watermarkPath = null): Server
    {
        $config = [
            'source' => dirname($inputFile),
            'cache' => $this->temporaryDirectory,
            'driver' => $this->imageDriver,
        ];

        if ($watermarkPath) {
            $config['watermarks'] = $watermarkPath;
        }

        return ServerFactory::create($config);
    }

    public function save(string $outputFile): void
    {
        if ($this->conversionResult === '' || $this->conversionResult === null) {
            copy($this->inputImage, $outputFile);

            return;
        }

        $conversionResultDirectory = pathinfo($this->conversionResult, PATHINFO_DIRNAME);

        copy($this->conversionResult, $outputFile);

        unlink($this->conversionResult);

        if ($this->directoryIsEmpty($conversionResultDirectory) && $conversionResultDirectory !== sys_get_temp_dir()) {
            rmdir($conversionResultDirectory);
        }
    }

    private function prepareManipulations(array $manipulationGroup): array
    {
        $glideManipulations = [];

        foreach ($manipulationGroup as $name => $argument) {
            if ($name !== 'optimize') {
                $glideManipulations[$this->convertToGlideParameter($name)] = $argument;
            }
        }

        return $glideManipulations;
    }

    private function convertToGlideParameter(string $manipulationName): string
    {
        return match ($manipulationName) {
                'width'             => 'w',
                'height'            => 'h',
                'blur'              => 'blur',
                'pixelate'          => 'pixel',
                'crop'              => 'fit',
                'manualCrop'        => 'crop',
                'orientation'       => 'or',
                'flip'              => 'flip',
                'fit'               => 'fit',
                'devicePixelRatio'  => 'dpr',
                'brightness'        => 'bri',
                'contrast'          => 'con',
                'gamma'             => 'gam',
                'sharpen'           => 'sharp',
                'filter'            => 'filt',
                'background'        => 'bg',
                'border'            => 'border',
                'quality'           => 'q',
                'format'            => 'fm',
                'watermark'         => 'mark',
                'watermarkWidth'    => 'markw',
                'watermarkHeight'   => 'markh',
                'watermarkFit'      => 'markfit',
                'watermarkPaddingX' => 'markx',
                'watermarkPaddingY' => 'marky',
                'watermarkPosition' => 'markpos',
                'watermarkOpacity'  => 'markalpha',
                default             => throw CouldNotConvert::unknownManipulation($manipulationName)
            };
    }

    private function directoryIsEmpty(string $directory): bool
    {
        $iterator = new FilesystemIterator($directory);

        return ! $iterator->valid();
    }
}
