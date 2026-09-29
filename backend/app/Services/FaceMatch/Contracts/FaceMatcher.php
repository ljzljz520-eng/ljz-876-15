<?php

namespace App\Services\FaceMatch\Contracts;

use Illuminate\Http\UploadedFile;

interface FaceMatcher
{
    /**
     * 将摄像头人脸照片与证件照片进行 1:1 比对。
     *
     * @return array{score:int,passed:bool,suspected:bool,raw:?string}
     */
    public function compare(UploadedFile $livePhoto, UploadedFile $idPhoto): array;
}
