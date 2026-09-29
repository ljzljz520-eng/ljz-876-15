<?php

namespace App\Services;

use RuntimeException;

/**
 * 人脸比对服务（本地演示实现）。
 *
 * 基于 GD 的感知哈希（aHash，16x16 灰度均值哈希）计算两张人脸图片的
 * 相似度（0 ~ 1）。相同/高度相似的照片得分趋近 1，差异越大得分越低。
 *
 * 生产环境可将 compare() 替换为云厂商人脸比对 API（阿里云/腾讯云等），
 * 调用方（VerificationController）无需改动。
 */
class FaceCompareService
{
    private const HASH_SIZE = 16;

    /**
     * 比对两张图片，返回相似度（0 ~ 1）。
     *
     * @param  string  $idCardImagePath  证件照绝对路径
     * @param  string  $faceImagePath    人脸抓拍绝对路径
     */
    public function compare(string $idCardImagePath, string $faceImagePath): float
    {
        $hashA = $this->perceptualHash($idCardImagePath);
        $hashB = $this->perceptualHash($faceImagePath);

        $distance = $this->hammingDistance($hashA, $hashB);
        $maxBits = self::HASH_SIZE * self::HASH_SIZE;

        return round(1 - ($distance / $maxBits), 4);
    }

    /**
     * 根据相似度给出系统判定状态。
     */
    public function classify(float $score): string
    {
        $pass = (float) config('verification.pass_threshold', 0.85);
        $suspicious = (float) config('verification.suspicious_threshold', 0.60);

        if ($score >= $pass) {
            return \App\Models\ExamVerification::STATUS_PASSED;
        }

        if ($score >= $suspicious) {
            return \App\Models\ExamVerification::STATUS_SUSPICIOUS;
        }

        return \App\Models\ExamVerification::STATUS_FAILED;
    }

    /**
     * 计算图片的感知哈希（返回 0/1 数组）。
     *
     * @return array<int, int>
     */
    private function perceptualHash(string $path): array
    {
        $image = $this->loadImage($path);
        if ($image === false) {
            throw new RuntimeException('无法解析图片文件');
        }

        $size = self::HASH_SIZE;
        $resized = imagecreatetruecolor($size, $size);
        imagecopyresampled(
            $resized,
            $image,
            0, 0, 0, 0,
            $size, $size,
            imagesx($image),
            imagesy($image)
        );

        // 灰度化并计算均值
        $grays = [];
        $sum = 0;
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $rgb = imagecolorat($resized, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $gray = (int) round($r * 0.299 + $g * 0.587 + $b * 0.114);
                $grays[] = $gray;
                $sum += $gray;
            }
        }

        imagedestroy($image);
        imagedestroy($resized);

        $avg = $sum / count($grays);

        return array_map(static fn (int $gray): int => $gray >= $avg ? 1 : 0, $grays);
    }

    /**
     * @param  array<int, int>  $a
     * @param  array<int, int>  $b
     */
    private function hammingDistance(array $a, array $b): int
    {
        $distance = 0;
        $count = min(count($a), count($b));
        for ($i = 0; $i < $count; $i++) {
            if ($a[$i] !== $b[$i]) {
                $distance++;
            }
        }

        return $distance;
    }

    /**
     * @return \GdImage|false
     */
    private function loadImage(string $path)
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return false;
        }

        return @imagecreatefromstring($contents);
    }
}
