<?php

namespace App\Services;

use App\Services\Face\BiznetFaceDriver;
use App\Services\Face\FaceDriverContract;
use App\Services\Face\HumanFaceDriver;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Public entry point for face recognition.  The concrete engine is selected by
 * config('face.driver') so callers never need to know which one is active; every
 * existing consumer keeps type-hinting this class unchanged.
 */
class FaceEngineClient
{
    private FaceDriverContract $driver;

    public function __construct(?FaceDriverContract $driver = null)
    {
        $this->driver = $driver ?? self::resolveDriver();
    }

    public function extractDescriptor(string $imageBase64): string
    {
        return $this->driver->extractDescriptor($imageBase64);
    }

    /**
     * Descriptor + skor kualitas foto pendaftaran. Driver tanpa skor kualitas
     * (mis. Biznet) mengembalikan quality kosong.
     *
     * @return array{descriptor: string, face_count: int, quality: array<string, mixed>}
     */
    public function extract(string $imageBase64): array
    {
        if ($this->driver instanceof HumanFaceDriver) {
            return $this->driver->extract($imageBase64);
        }

        return [
            'descriptor' => $this->driver->extractDescriptor($imageBase64),
            'face_count' => 1,
            'quality' => [],
        ];
    }

    /**
     * threshold & quality hanya ada bila driver menyediakannya.
     *
     * @return array{matched: bool, similarity: float, faceCount: int, threshold?: float, quality?: array<string, mixed>}
     */
    public function compareDescriptorWithImage(string $imageBase64, string $referenceDescriptor): array
    {
        return $this->driver->compareDescriptorWithImage($imageBase64, $referenceDescriptor);
    }

    private static function resolveDriver(): FaceDriverContract
    {
        $driver = (string) config('face.driver', 'human');

        return match ($driver) {
            'human' => new HumanFaceDriver(),
            'biznet' => new BiznetFaceDriver(),
            default => throw new ServiceUnavailableHttpException(
                null,
                sprintf('Driver wajah "%s" tidak dikenal.', $driver)
            ),
        };
    }
}
