<?php
require_once '../includes/functions.php';

$action = $_POST['action'] ?? '';

if ($action === 'upload_photo') {
    $personId = (int)$_POST['person_id'];
    $file = $_FILES['photo'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        die('Ошибка загрузки');
    }
    
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
        die('Недопустимый формат');
    }
    
    $newName = uniqid('photo_') . '.' . $ext;
    $dest = '../uploads/photos/' . $newName;
    
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        die('Не удалось сохранить файл');
    }
    
    // Создаём превью
    $thumbName = 'thumb_' . $newName;
    $thumbDest = '../uploads/thumbs/' . $thumbName;
    createThumb($dest, $thumbDest, 200);
    
    // Обновляем основное фото человека
    $stmt = $pdo->prepare("UPDATE persons SET photo = ? WHERE id = ?");
    $stmt->execute(['uploads/photos/' . $newName, $personId]);
    
    // Сохраняем в media
    $stmt = $pdo->prepare("INSERT INTO media (person_id, file_path, thumb_path, file_type) VALUES (?, ?, ?, 'photo')");
    $stmt->execute([$personId, 'uploads/photos/' . $newName, 'uploads/thumbs/' . $thumbName]);
    
    header("Location: ../person.php?id=$personId");
    exit;
}

if ($action === 'upload_media') {
    $personId = (int)($_POST['person_id'] ?? 0);
    $eventId = (int)($_POST['event_id'] ?? 0) ?: null;
    
    foreach ($_FILES['files']['tmp_name'] as $i => $tmp) {
        if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) continue;
        
        $ext = strtolower(pathinfo($_FILES['files']['name'][$i], PATHINFO_EXTENSION));
        $newName = uniqid('media_') . '.' . $ext;
        $dest = '../uploads/photos/' . $newName;
        
        if (move_uploaded_file($tmp, $dest)) {
            $type = in_array($ext, ['jpg','jpeg','png','gif','webp']) ? 'photo' : 
                   (in_array($ext, ['mp3','wav','ogg']) ? 'audio' : 
                   (in_array($ext, ['mp4','mov','avi']) ? 'video' : 'document'));
            
            $thumbPath = null;
            if ($type === 'photo') {
                $thumbName = 'thumb_' . $newName;
                createThumb($dest, '../uploads/thumbs/' . $thumbName, 200);
                $thumbPath = 'uploads/thumbs/' . $thumbName;
            }
            
            $stmt = $pdo->prepare("INSERT INTO media (person_id, event_id, file_path, thumb_path, file_type) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$personId, $eventId, 'uploads/photos/' . $newName, $thumbPath, $type]);
        }
    }
    
    jsonResponse(['success' => true]);
}

function createThumb(string $src, string $dst, int $maxSize): void {
    if (!file_exists($src)) return;
    $info = @getimagesize($src);
    if (!$info) return;
    
    [$w, $h, $type] = $info;
    $ratio = min($maxSize / $w, $maxSize / $h);
    $nw = (int)($w * $ratio);
    $nh = (int)($h * $ratio);
    
    $srcImg = match($type) {
        IMAGETYPE_JPEG => imagecreatefromjpeg($src),
        IMAGETYPE_PNG => imagecreatefrompng($src),
        IMAGETYPE_GIF => imagecreatefromgif($src),
        IMAGETYPE_WEBP => imagecreatefromwebp($src),
        default => null,
    };
    if (!$srcImg) return;
    
    $dstImg = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $nw, $nh, $w, $h);
    
    match($type) {
        IMAGETYPE_JPEG => imagejpeg($dstImg, $dst, 85),
        IMAGETYPE_PNG => imagepng($dstImg, $dst),
        IMAGETYPE_GIF => imagegif($dstImg, $dst),
        IMAGETYPE_WEBP => imagewebp($dstImg, $dst, 85),
        default => null,
    };
    
    imagedestroy($srcImg);
    imagedestroy($dstImg);
}