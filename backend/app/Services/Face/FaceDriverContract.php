<?php

namespace App\Services\Face;

interface FaceDriverContract
{
    /**
     * Turn a captured selfie into whatever opaque reference this driver needs in
     * order to recognise the same face later.  The return value is stored as-is
     * in users.face_descriptor and is only ever read back by the same driver.
     */
    public function extractDescriptor(string $imageBase64): string;

    /**
     * @return array{matched: bool, similarity: float, faceCount: int}
     */
    public function compareDescriptorWithImage(string $imageBase64, string $referenceDescriptor): array;
}
