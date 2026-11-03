<?php
require_once __DIR__ . '/../includes/functions.php';

// Отключаем буферизацию и показываем ошибки для отладки
ob_clean();
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: application/json; charset=utf-8');

$action = $_REQUEST['action'] ?? '';

// Функция создания миниатюры
function createThumb(string $src, string $dst, int $maxSize): bool {
    try {
        if (!file_exists($src)) {
            error_log("createThumb: исходный файл не существует: $src");
            return false;
        }
        
        $info = @getimagesize($src);
        if (!$info) {
            error_log("createThumb: не удалось получить информацию о файле: $src");
            return false;
        }
        
        [$w, $h, $type] = $info;
        $ratio = min($maxSize / $w, $maxSize / $h);
        $nw = (int)($w * $ratio);
        $nh = (int)($h * $ratio);
        
        $srcImg = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
            IMAGETYPE_PNG => @imagecreatefrompng($src),
            IMAGETYPE_GIF => @imagecreatefromgif($src),
            IMAGETYPE_WEBP => @imagecreatefromwebp($src),
            default => null,
        };
        
        if (!$srcImg) {
            error_log("createThumb: не удалось создать изображение из: $src (тип: $type)");
            return false;
        }
        
        $dstImg = imagecreatetruecolor($nw, $nh);
        if (!$dstImg) {
            error_log("createThumb: не удалось создать цветовое изображение");
            imagedestroy($srcImg);
            return false;
        }
        
        // Для PNG с прозрачностью
        imagealphablending($dstImg, false);
        imagesavealpha($dstImg, true);
        
        imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $nw, $nh, $w, $h);
        
        $result = match ($type) {
            IMAGETYPE_JPEG => @imagejpeg($dstImg, $dst, 85),
            IMAGETYPE_PNG => @imagepng($dstImg, $dst),
            IMAGETYPE_GIF => @imagegif($dstImg, $dst),
            IMAGETYPE_WEBP => @imagewebp($dstImg, $dst, 85),
            default => false,
        };
        
        imagedestroy($srcImg);
        imagedestroy($dstImg);
        
        if (!$result) {
            error_log("createThumb: не удалось сохранить миниатюру: $dst");
            return false;
        }
        
        return true;
    } catch (Exception $e) {
        error_log("createThumb exception: " . $e->getMessage());
        return false;
    }
}

// Обработка загрузки фото
if ($action === 'upload_photo') {
    try {
        $personId = (int)($_POST['person_id'] ?? 0);
        if ($personId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Не указан ID человека'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        if (!isset($_FILES['photo'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Файл не получен'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $file = $_FILES['photo'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'Превышен upload_max_filesize в php.ini',
                UPLOAD_ERR_FORM_SIZE => 'Превышен MAX_FILE_SIZE в форме',
                UPLOAD_ERR_PARTIAL => 'Файл загружен не полностью',
                UPLOAD_ERR_NO_FILE => 'Файл не был загружен',
                UPLOAD_ERR_NO_TMP_DIR => 'Отсутствует временная папка',
                UPLOAD_ERR_CANT_WRITE => 'Не удалось записать файл на диск',
                UPLOAD_ERR_EXTENSION => 'Расширение PHP остановило загрузку',
            ];
            $errorMsg = $errorMessages[$file['error']] ?? 'Неизвестная ошибка загрузки';
            error_log("Upload error: " . $errorMsg . " (code: " . $file['error'] . ")");
            http_response_code(400);
            echo json_encode(['error' => $errorMsg], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // Проверка MIME-типа
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mime, $allowedMimes, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Недопустимый тип файла: ' . $mime], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        $ext = $extMap[$mime];
        
        // Создаём директории если не существуют
        $photosDir = __DIR__ . '/../uploads/photos/';
        $thumbsDir = __DIR__ . '/../uploads/thumbs/';
        
        if (!file_exists($photosDir)) {
            if (!mkdir($photosDir, 0755, true)) {
                error_log("Не удалось создать директорию: $photosDir");
                http_response_code(500);
                echo json_encode(['error' => 'Не удалось создать директорию для фото'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        
        if (!file_exists($thumbsDir)) {
            if (!mkdir($thumbsDir, 0755, true)) {
                error_log("Не удалось создать директорию: $thumbsDir");
                http_response_code(500);
                echo json_encode(['error' => 'Не удалось создать директорию для миниатюр'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        
        // Проверка прав доступа
        if (!is_writable($photosDir)) {
            error_log("Директория не доступна для записи: $photosDir");
            http_response_code(500);
            echo json_encode(['error' => 'Директория для фото не доступна для записи'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $newName = uniqid('photo_') . '.' . $ext;
        $dest = $photosDir . $newName;
        
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            error_log("Не удалось переместить загруженный файл в: $dest");
            http_response_code(500);
            echo json_encode(['error' => 'Не удалось сохранить файл'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // Создаём превью
        $thumbName = 'thumb_' . $newName;
        $thumbDest = $thumbsDir . $thumbName;
        
        if (!createThumb($dest, $thumbDest, 200)) {
            error_log("Не удалось создать миниатюру");
            // Не прерываем процесс, фото всё равно загружено
        }
        
        // Обновляем основное фото человека
        $stmt = $pdo->prepare("UPDATE persons SET photo = ? WHERE id = ?");
        $stmt->execute(['uploads/photos/' . $newName, $personId]);
        
        // Сохраняем в media
        $stmt = $pdo->prepare("INSERT INTO media (person_id, file_path, thumb_path, file_type) VALUES (?, ?, ?, 'photo')");
        $stmt->execute([
            $personId, 
            'uploads/photos/' . $newName, 
            file_exists($thumbDest) ? 'uploads/thumbs/' . $thumbName : null
        ]);
        
        // Для обычной формы возвращаем редирект
        if (!isset($_GET['ajax']) && !isset($_POST['ajax'])) {
            header("Location: ../person.php?id=$personId&upload_success=1");
            exit;
        }
        
        // Для AJAX возвращаем JSON
        echo json_encode(['success' => true, 'photo' => 'uploads/photos/' . $newName], JSON_UNESCAPED_UNICODE);
        exit;
        
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Ошибка базы данных: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Exception $e) {
        error_log("General error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Ошибка сервера: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}


if ($action === 'upload_photo') {
    $personId = (int)$_POST['person_id'];
    $file = $_FILES['photo'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'Ошибка загрузки'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mime, $allowedMimes, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Недопустимый тип файла'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $ext = $extMap[$mime];
    
    $newName = uniqid('photo_') . '.' . $ext;
    $dest = '../uploads/photos/' . $newName;
    
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        http_response_code(500);
        echo json_encode(['error' => 'Не удалось сохранить файл'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $thumbName = 'thumb_' . $newName;
    $thumbDest = '../uploads/thumbs/' . $thumbName;
    createThumb($dest, $thumbDest, 200);
    
    $stmt = $pdo->prepare("UPDATE persons SET photo = ? WHERE id = ?");
    $stmt->execute(['uploads/photos/' . $newName, $personId]);
    
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
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $tmp);
        finfo_close($finfo);
        
        $typeMap = [
            'image/jpeg' => ['photo', 'jpg'],
            'image/png' => ['photo', 'png'],
            'image/gif' => ['photo', 'gif'],
            'image/webp' => ['photo', 'webp'],
            'audio/mpeg' => ['audio', 'mp3'],
            'audio/wav' => ['audio', 'wav'],
            'audio/ogg' => ['audio', 'ogg'],
            'video/mp4' => ['video', 'mp4'],
            'video/quicktime' => ['video', 'mov'],
            'video/x-msvideo' => ['video', 'avi']
        ];
        
        if (!isset($typeMap[$mime])) continue;
        
        [$type, $ext] = $typeMap[$mime];
        $newName = uniqid('media_') . '.' . $ext;
        $dest = '../uploads/photos/' . $newName;
        
        if (move_uploaded_file($tmp, $dest)) {
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
    
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'upload_general') {
    $personId = (int)($_POST['person_id'] ?? 0) ?: null;
    $eventId = (int)($_POST['event_id'] ?? 0) ?: null;
    $commonCaption = trim($_POST['caption'] ?? '');
    
    $uploaded = 0;
    
    foreach ($_FILES['files']['tmp_name'] as $i => $tmp) {
        if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) continue;
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $tmp);
        finfo_close($finfo);
        
        $typeMap = [
            'image/jpeg' => ['photo', 'jpg'],
            'image/png' => ['photo', 'png'],
            'image/gif' => ['photo', 'gif'],
            'image/webp' => ['photo', 'webp'],
            'audio/mpeg' => ['audio', 'mp3'],
            'audio/wav' => ['audio', 'wav'],
            'audio/ogg' => ['audio', 'ogg'],
            'audio/mp4' => ['audio', 'm4a'],
            'video/mp4' => ['video', 'mp4'],
            'video/quicktime' => ['video', 'mov'],
            'video/x-msvideo' => ['video', 'avi'],
            'video/webm' => ['video', 'webm'],
            'application/pdf' => ['document', 'pdf'],
            'application/msword' => ['document', 'doc'],
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['document', 'docx'],
        ];
        
        if (!isset($typeMap[$mime])) continue;
        
        [$type, $ext] = $typeMap[$mime];
        $newName = uniqid('media_') . '.' . $ext;
        $destDir = __DIR__ . '/../uploads/photos/';
        if (!file_exists($destDir)) mkdir($destDir, 0755, true);
        $dest = $destDir . $newName;
        
        if (move_uploaded_file($tmp, $dest)) {
            $thumbPath = null;
            if ($type === 'photo') {
                $thumbName = 'thumb_' . $newName;
                $thumbDir = __DIR__ . '/../uploads/thumbs/';
                if (!file_exists($thumbDir)) mkdir($thumbDir, 0755, true);
                createThumb($dest, $thumbDir . $thumbName, 400);
                $thumbPath = 'uploads/thumbs/' . $thumbName;
            }
            
            $stmt = $pdo->prepare("INSERT INTO media (person_id, event_id, file_path, thumb_path, file_type, caption) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $personId,
                $eventId,
                'uploads/photos/' . $newName,
                $thumbPath,
                $type,
                $commonCaption
            ]);
            $uploaded++;
        }
    }
    
    echo json_encode(['success' => true, 'uploaded' => $uploaded], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'update_caption') {
    $id = (int)($_POST['id'] ?? 0);
    $caption = trim($_POST['caption'] ?? '');
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Не указан ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $stmt = $pdo->prepare("UPDATE media SET caption = ? WHERE id = ?");
    $stmt->execute([$caption, $id]);
    
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'update_media_info') {
    $id = (int)($_POST['id'] ?? 0);
    $personId = isset($_POST['person_id']) && $_POST['person_id'] !== '' ? (int)$_POST['person_id'] : null;
    $eventId = isset($_POST['event_id']) && $_POST['event_id'] !== '' ? (int)$_POST['event_id'] : null;
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Не указан ID медиафайла'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("UPDATE media SET person_id = ?, event_id = ? WHERE id = ?");
        $stmt->execute([$personId, $eventId, $id]);
        
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Не указан ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT file_path, thumb_path FROM media WHERE id = ?");
    $stmt->execute([$id]);
    $media = $stmt->fetch();
    
    if ($media) {
        $basePath = __DIR__ . '/../';
        if ($media['file_path'] && file_exists($basePath . $media['file_path'])) {
            @unlink($basePath . $media['file_path']);
        }
        if ($media['thumb_path'] && file_exists($basePath . $media['thumb_path'])) {
            @unlink($basePath . $media['thumb_path']);
        }
        
        $pdo->prepare("DELETE FROM media WHERE id = ?")->execute([$id]);
        
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Файл не найден'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'list') {
    try {
        $stmt = $pdo->query("SELECT id, event_type, event_date FROM events ORDER BY event_date DESC");
        $events = $stmt->fetchAll();
        echo json_encode($events, JSON_UNESCAPED_UNICODE);
        exit;
    } catch (PDOException $e) {
        echo json_encode([], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

http_response_code(400);
echo json_encode(['error' => 'Неизвестное действие: ' . $action], JSON_UNESCAPED_UNICODE);
exit;