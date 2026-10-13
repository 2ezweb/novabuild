<?php
// Shared file-upload handling: offer attachments, work hand-over files and
// chat files all go through store_uploaded_files() so the whitelist and the
// checks live in exactly one place.

const UPLOAD_MAX_FILE_SIZE = 15 * 1024 * 1024; // 15MB per file
const UPLOAD_MAX_FILES     = 10;               // per request

// extension => list of MIME types finfo may legitimately report for it.
// docx/xlsx are zip containers — finfo very commonly reports them as plain
// application/zip, so that's accepted too and double-checked with ZipArchive.
const UPLOAD_ALLOWED_TYPES = [
    'png'  => ['image/png'],
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'pdf'  => ['application/pdf'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
];

const UPLOAD_FORMATS_HINT = 'png/jpg/pdf/docx/xlsx, до 15 МБ кожен, не більше ' . UPLOAD_MAX_FILES . ' файлів за раз';

// Saves every valid file from a multi-file <input name="files[]"> field into
// uploads/<subdir>/ under a random name. Invalid files are skipped silently;
// the caller decides what to do when nothing was saved.
// Returns [['file_path','original_name','mime_type','size'], ...].
function store_uploaded_files(?array $field, string $subdir): array {
    if (!$field || !is_array($field['name'] ?? null)) return [];

    $dir = __DIR__ . '/../uploads/' . $subdir;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $count = min(count($field['name']), UPLOAD_MAX_FILES);
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $saved = [];

    for ($i = 0; $i < $count; $i++) {
        if ($field['error'][$i] !== UPLOAD_ERR_OK) continue;

        $originalName = (string) $field['name'][$i];
        $tmpPath      = $field['tmp_name'][$i];
        $size         = (int) $field['size'][$i];
        $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!isset(UPLOAD_ALLOWED_TYPES[$ext]) || $size > UPLOAD_MAX_FILE_SIZE) continue;

        $mime = finfo_file($finfo, $tmpPath);
        if (!in_array($mime, UPLOAD_ALLOWED_TYPES[$ext], true)) continue;

        // docx/xlsx must really be zip packages, not a renamed arbitrary file.
        if (in_array($ext, ['docx', 'xlsx'], true)) {
            $zip = new ZipArchive();
            if ($zip->open($tmpPath) !== true) continue;
            $zip->close();
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($tmpPath, $dir . '/' . $filename)) continue;

        $saved[] = [
            'file_path'     => 'uploads/' . $subdir . '/' . $filename,
            'original_name' => mb_substr($originalName, 0, 255),
            'mime_type'     => $mime,
            'size'          => $size,
        ];
    }

    return $saved;
}

// Undo a store_uploaded_files() call when the DB write that should follow it fails.
function delete_stored_files(array $saved): void {
    foreach ($saved as $f) {
        $path = __DIR__ . '/../' . $f['file_path'];
        if (is_file($path)) @unlink($path);
    }
}
