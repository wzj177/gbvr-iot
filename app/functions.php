<?php
/**
 * Here is your custom functions.
 */

use CoreW\Business\Attachment\Exception\AttachmentException;

if (!function_exists('format_bytes')) {
    function format_bytes(int $bytes) {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 2) . 'kB';
        }

        if ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024), 2) . ' MB';
        }

        return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
    }
}

if (!function_exists('format_duration')) {
    function format_duration(int $duration) {
        if ($duration < 60) {
            return $duration . 's';
        }

        if ($duration < 3600) {
            $m = floor($duration / 60);
            $s = $duration % 60;
            return $m . 'm' . $s . 's';
        }

        $h = floor($duration / 3600);
        $diff = $duration - 3600 * $h;
        $m = floor($diff / 60);
        $s = $diff % 60;

        return $h . 'h' . $m . 'm' . $s . 's';
    }
}

if (!function_exists('attachment_validate_upload_file')) {
    function attachment_validate_upload_file($setting, $ext, $size) {
        $imgExts = !is_array($setting['allow_image_exts']) || empty($setting['allow_image_exts']) ? [] : $setting['allow_image_exts'];
        $audioExts = !is_array($setting['allow_audio_exts']) || empty($setting['allow_audio_exts']) ? [] : $setting['allow_audio_exts'];
        $videoExts = !is_array($setting['allow_video_exts']) || empty($setting['allow_video_exts']) ? [] : $setting['allow_video_exts'];
        $otherFileExts = !is_array($setting['allow_file_exts']) || empty($setting['allow_file_exts']) ? [] : $setting['allow_file_exts'];

        if (!in_array($ext, array_merge($imgExts, $audioExts, $videoExts, $otherFileExts))) {
            throw AttachmentException::EXTENSION_NOT_ALLOWED();
        }

        if (in_array($ext, $imgExts)) {
            if ((int)$setting['allow_image_upload_size'] * 1024 < $size) {
                throw new AttachmentException(AttachmentException::IMAGE_FILE_SIZE_INVALID, "图片大小不能超过" . sprintf("%.2f", $setting['allow_image_upload_size'] / 1024) . "M");
            }
        }

        if (in_array($ext, $audioExts)) {
            if ((int)$setting['allow_audio_upload_size'] * 1024 < $size) {
                throw new AttachmentException(AttachmentException::AUDIO_FILE_SIZE_INVALID, "音频大小不能超过" . sprintf("%.2f", $setting['allow_audio_upload_size'] / 1024) . "M");
            }
        }

        if (in_array($ext, $videoExts)) {
            if ((int)$setting['allow_video_upload_size'] * 1024 < $size) {
                throw new AttachmentException(AttachmentException::VIDEO_FILE_SIZE_INVALID, "视频大小不能超过" . sprintf("%.2f", $setting['allow_video_upload_size'] / 1024) . "M");
            }
        }

        if (in_array($ext, $otherFileExts)) {
            if ((int)$setting['allow_file_upload_size'] * 1024 < $size) {
                throw new AttachmentException(AttachmentException::OTHER_FILE_SIZE_INVALID, "文件大小不能超过" . sprintf("%.2f", $setting['allow_file_upload_size'] / 1024) . "M");
            }
        }

        return true;
    }
}